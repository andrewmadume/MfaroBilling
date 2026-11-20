<?php

namespace App\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\Money;
use Brick\Math\BigInteger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(private PayFastGateway $gateway, private GatewayOperationService $operations, private BillingService $billing) {}

    public function quote(Payment $payment, string $amount, string $reason): array
    {
        abort_unless($payment->provider === 'payfast' && $payment->currency === 'ZAR' && $payment->status === 'completed', 409, 'Only completed ZAR PayFast payments are eligible.');
        $minor = Money::minor($amount);
        abort_unless($minor > 0, 422, 'Refund amount must be greater than zero.');
        $refunded = $this->refunded($payment->id);
        abort_if($refunded + $minor > Money::minor($payment->amount), 422, 'Refund exceeds the unrefunded payment amount.');
        abort_if(DB::table('payment_refunds')->where('payment_id', $payment->id)->count() !== DB::table('credit_notes')->where('payment_id', $payment->id)->count(), 409, 'Backfill legacy credit notes before issuing another refund.');
        $remote = $this->gateway->refundEligibility($payment->provider_reference);
        $available = $remote['amount_available_for_refund'] ?? null;
        abort_unless(is_int($available) || is_numeric($available), 502, 'PayFast did not supply the refundable balance.');
        abort_unless((int) ($remote['amount_original'] ?? -1) === Money::minor($payment->amount), 502, 'Provider original amount does not match the payment.');
        abort_unless(($remote['status'] ?? '') === 'REFUNDABLE' && $minor <= (int) $available, 409, 'The provider does not permit this refund.');
        $method = $remote[$minor === Money::minor($payment->amount) ? 'refund_full' : 'refund_partial']['method'] ?? 'NOT_AVAILABLE';
        abort_unless($method === 'PAYMENT_SOURCE', 422, 'This refund requires a bank payout or unsupported method. Complete it in the merchant dashboard and record the external refund.');
        $quote = ['payment_id' => $payment->id, 'amount' => Money::decimal($minor), 'currency' => $payment->currency, 'reason' => $reason, 'local_refunded_minor' => $refunded, 'provider_available_minor' => (int) $available, 'method' => 'PAYMENT_SOURCE', 'execution_enabled' => (bool) config('billing.payfast.refunds_enabled')];
        $quote['quote_hash'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));

        return $quote;
    }

    public function issue(Payment $payment, array $data, string $key, int $user): array
    {
        $payload = ['payment_id' => $payment->id, 'amount' => $data['amount'], 'reason' => $data['reason'], 'quote_hash' => $data['quote_hash']];
        $replay = $this->operations->replay($payment->organisation_id, $key, 'refund', $payload);
        if ($replay) {
            return $replay;
        }
        abort_unless(config('billing.payfast.refunds_enabled'), 503, 'Gateway refund execution is disabled. Configure and acceptance-test it first.');
        $quote = $this->quote($payment, $data['amount'], $data['reason']);
        abort_unless(hash_equals($quote['quote_hash'], $data['quote_hash']), 409, 'Refund eligibility changed. Review a fresh quote.');
        $intent = $this->operations->begin($payment->organisation_id, $key, 'refund', $payload, $payment->subscription_id, $payment->id, $user, function () use ($payment, $quote) {
            Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->refunded($payment->id) === $quote['local_refunded_minor'], 409, 'The payment changed while refunding. Review a new quote.');
        });
        if (! $intent['new']) {
            return $intent['operation'];
        }$id = $intent['operation']['id'];
        try {
            $this->gateway->refund($payment->provider_reference, Money::minor($data['amount']), $data['reason']);
            DB::table('gateway_operations')->where('id', $id)->update(['result' => json_encode(['provider_accepted' => true]), 'updated_at' => now()]);

            return $this->finalize($id);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->operations->uncertain($id);
        }
    }

    public function finalize(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $op = DB::table('gateway_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($op && $op->kind === 'refund', 404);
            if ($op->status === 'succeeded') {
                return $this->operations->view($id);
            } $result = json_decode($op->result ?? '{}', true);
            abort_unless($result['provider_accepted'] ?? false, 409, 'This refund requires an operator to verify provider evidence.');
            $payload = json_decode($op->payload, true);
            $payment = Payment::whereKey($op->payment_id)->lockForUpdate()->firstOrFail();
            $note = $this->record($payment, $payload['amount'], 'payfast-operation-'.$op->id, $payload['reason']);
            $this->billing->event(Subscription::findOrFail($payment->subscription_id), 'payment_refunded', ['operation_id' => $id, 'credit_note_id' => $note->id]);
            $this->billing->notify(Subscription::findOrFail($payment->subscription_id), 'refund_confirmation', 'refund:'.$id, ['credit_note_number' => $note->number]);

            return $this->operations->complete($id, ['credit_note_id' => $note->id, 'credit_note_number' => $note->number, 'refunded_amount' => $note->total, 'currency' => $note->currency]);
        }, 3);
    }

    public function recordExternal(Payment $payment, string $amount, string $reference, string $reason, ?int $user): object
    {
        return DB::transaction(function () use ($payment, $amount, $reference, $reason, $user) {
            Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('gateway_operations')->where('payment_id', $payment->id)->whereIn('status', GatewayOperationService::UNRESOLVED)->exists(), 409, 'A gateway refund is unresolved. Reconcile that operation instead.');
            $note = $this->record($payment, $amount, $reference, $reason);
            $this->operations->audit($note->id, $payment->organisation_id, $user, 'refund.externally_recorded', ['payment_id' => $payment->id]);

            return $note;
        });
    }

    private function refunded(string $payment): int
    {
        return (int) DB::table('payment_refunds')->where('payment_id', $payment)->get()->sum(fn ($r) => Money::minor((string) $r->amount));
    }

    private function record(Payment $payment, string $amount, string $reference, string $reason): object
    {
        abort_unless(Money::minor($amount) > 0, 422, 'Refund amount must be greater than zero.');
        $existing = DB::table('payment_refunds')->where('reference', $reference)->first();
        if ($existing) {
            abort_unless($existing->payment_id === $payment->id && Money::minor((string) $existing->amount) === Money::minor($amount) && $existing->reason === $reason, 409, 'Refund reference already represents another transaction.');

            return $this->credit($existing->id);
        }
        abort_if($this->refunded($payment->id) + Money::minor($amount) > Money::minor($payment->amount), 422, 'Refund exceeds payment.');
        $id = (string) Str::uuid();
        DB::table('payment_refunds')->insert(['id' => $id, 'payment_id' => $payment->id, 'amount' => $amount, 'reference' => $reference, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);

        return $this->credit($id);
    }

    public function credit(string $refundId): object
    {
        $existing = DB::table('credit_notes')->where('payment_refund_id', $refundId)->first();
        if ($existing) {
            return $existing;
        }
        $refund = DB::table('payment_refunds')->find($refundId);
        abort_unless($refund, 404);
        $p = Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::findOrFail($p->invoice_id);
        $total = Money::minor($p->amount);
        abort_unless($total > 0 && $total === Money::minor($invoice->total), 409, 'This payment requires manual invoice allocation.');
        $notes = DB::table('credit_notes')->where('payment_id', $p->id)->get();
        $previous = (int) $notes->sum(fn ($n) => Money::minor((string) $n->total));
        $taxAlready = (int) $notes->sum(fn ($n) => Money::minor((string) $n->tax));
        $minor = Money::minor((string) $refund->amount);
        abort_if($previous + $minor > $total, 409, 'Credits exceed the original payment.');
        $cumulativeTax = BigInteger::of($previous + $minor)->multipliedBy(Money::minor($invoice->tax))->plus(intdiv($total, 2))->quotient($total)->toInt();
        $tax = $cumulativeTax - $taxAlready;
        $id = (string) Str::uuid();
        DB::table('credit_notes')->insert(['id' => $id, 'organisation_id' => $p->organisation_id, 'invoice_id' => $invoice->id, 'payment_id' => $p->id, 'payment_refund_id' => $refundId, 'number' => 'MC-'.now()->format('Y').'-'.strtoupper(Str::random(12)), 'currency' => $p->currency, 'subtotal' => Money::decimal($minor - $tax), 'tax' => Money::decimal($tax), 'total' => Money::decimal($minor), 'reason' => $refund->reason, 'issued_at' => $refund->created_at, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('credit_notes')->find($id);
    }

    public function reconcile(string $id): array
    {
        $op = DB::table('gateway_operations')->find($id);
        abort_unless($op && $op->kind === 'refund', 404);
        if ($op->status === 'succeeded') {
            return $this->operations->view($id);
        }if ($op->status === 'processing') {
            abort_if(now()->diffInSeconds(Carbon::parse($op->updated_at), true) < 120, 409, 'An operation may still be running. Wait before reconciliation.');
            $this->operations->uncertain($id);
        }if ((json_decode($op->result ?? '{}', true)['provider_accepted'] ?? false)) {
            return $this->finalize($id);
        }

return $this->operations->view($id);
    }

    public function resolve(string $id, int $user, string $evidence, string $outcome): array
    {
        return DB::transaction(function () use ($id, $user, $evidence, $outcome) {
            $op = DB::table('gateway_operations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($op && $op->kind === 'refund' && $op->status === 'needs_reconciliation', 409, 'Only uncertain refunds can be resolved.');
            $this->operations->audit($id, $op->organisation_id, $user, 'refund.operator_resolution', ['evidence_reference' => $evidence, 'outcome' => $outcome]);
            if ($outcome === 'confirmed_not_processed') {
                DB::table('gateway_operations')->where('id',$id)->update(['status' => 'failed', 'failure_code' => 'operator_confirmed_not_processed', 'completed_at' => now(), 'updated_at' => now()]);

                return $this->operations->view($id);
            }
            DB::table('gateway_operations')->where('id',$id)->update(['result' => json_encode(['provider_accepted' => true, 'operator_evidence' => $evidence])]);

            return $this->finalize($id);
        });
    }
}
