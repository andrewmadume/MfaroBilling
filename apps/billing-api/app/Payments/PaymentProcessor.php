<?php

namespace App\Payments;

use App\Models\Invoice;
use App\Models\OfferPrice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentProcessor
{
    public function __construct(private BillingService $billing) {}

    // Only call after the adapter's complete verification; this service never accepts browser success redirects.
    public function process(array $event): void
    {
        DB::transaction(function () use ($event) {
            $s = Subscription::whereKey($event['m_payment_id'])->lockForUpdate()->firstOrFail();
            $key = $event['pf_payment_id'].':'.$event['payment_status'];
            if (DB::table('gateway_webhook_events')->where('provider', 'payfast')->where('event_key', $key)->exists()) {
                return;
            }
            $change = DB::table('subscription_changes')->where('subscription_id', $s->id)->whereIn('status', ['scheduled', 'needs_reconciliation'])->where('effective_at', '<=', now()->addHours(12))->lockForUpdate()->first();
            if ($event['payment_status'] === 'COMPLETE' && $change && Money::minor($event['amount_gross']) === Money::minor($change->amount)) {
                $snapshot = json_decode($change->snapshot, true);
                $price = OfferPrice::findOrFail($change->offer_price_id);
                $s->update(['offer_id' => $price->offer_id, 'offer_price_id' => $price->id, 'amount' => $change->amount, 'interval' => $snapshot['interval'], 'snapshot' => $snapshot]);
                DB::table('subscription_changes')->where('id', $change->id)->update(['status' => 'applied', 'applied_at' => now(), 'updated_at' => now()]);
                DB::table('entitlement_grants')->where('subscription_id', $s->id)->delete();
                $this->billing->event($s, 'plan_change_applied', ['change_id' => $change->id]);
                // An unpaid old-price renewal invoice cannot represent the new commercial agreement.
                Invoice::where('subscription_id', $s->id)->where('status', 'open')->update(['status' => 'void']);
            }
            abort_unless($s->currency === 'ZAR' && Money::minor($event['amount_gross']) === Money::minor($s->amount), 422, 'Payment amount mismatch.');
            if ($s->gateway_token) {
                abort_unless(isset($event['token']) && hash_equals($s->gateway_token, $event['token']), 422, 'Recurring subscription token mismatch.');
            }
            DB::table('gateway_webhook_events')->insert(['id' => Str::uuid(), 'provider' => 'payfast', 'event_key' => $key, 'payload' => json_encode(array_intersect_key($event, array_flip(['pf_payment_id', 'm_payment_id', 'payment_status', 'amount_gross']))), 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($event['payment_status'] === 'COMPLETE') {
                if (Payment::where('provider', 'payfast')->where('provider_reference', $event['pf_payment_id'])->exists()) {
                    return;
                }
                // A late payment after cancellation is retained for manual reconciliation; never silently restore access.
                $terminal = in_array($s->status, ['cancelled', 'expired'], true);
                $i = Invoice::where('subscription_id', $s->id)->where('status', 'open')->oldest()->first() ?? $this->billing->invoice($s);
                $p = Payment::create(['organisation_id' => $s->organisation_id, 'subscription_id' => $s->id, 'invoice_id' => $i->id, 'provider' => 'payfast', 'provider_reference' => $event['pf_payment_id'], 'amount' => $s->amount, 'currency' => $s->currency, 'status' => $terminal ? 'needs_review' : 'completed', 'paid_at' => now()]);
                $i->update(['status' => 'paid']);
                DB::table('payment_attempts')->where('invoice_id', $i->id)->update(['status' => 'completed', 'updated_at' => now()]);
                if (! $terminal) {
                    abort_unless(! empty($event['token']), 422, 'Recurring authorisation token is required.');
                    $start = $s->period_end && $s->period_end->isFuture() ? $s->period_end : now()->toImmutable();
                    $end = $s->interval === 'annual' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
                    $this->billing->transition($s, 'active');
                    $s->update(['period_start' => $start, 'period_end' => $end, 'grace_ends_at' => null, 'gateway_token' => $event['token'], 'gateway_status' => 'active']);
                    $this->billing->grant($s);
                }
                $this->billing->event($s, 'payment_received', ['payment_id' => $p->id, 'needs_review' => $terminal]);
                $this->billing->notify($s, 'payment_confirmation', 'payment:'.$p->id, ['invoice_number' => $i->number]);
            } elseif ($event['payment_status'] === 'FAILED') {
                if (in_array($s->status, ['active', 'past_due', 'grace_period'], true)) {
                    $this->billing->transition($s, 'past_due');
                    $s->update(['grace_ends_at' => ($s->grace_ends_at ?? now()->addDays(config('billing.grace_days')))]);
                    $i = Invoice::where('subscription_id', $s->id)->where('status', 'open')->first() ?? $this->billing->invoice($s);
                    DB::table('payment_attempts')->insert(['id' => Str::uuid(), 'organisation_id' => $s->organisation_id, 'subscription_id' => $s->id, 'invoice_id' => $i->id, 'status' => 'failed', 'amount' => $s->amount, 'currency' => $s->currency, 'created_at' => now(), 'updated_at' => now()]);
                    $this->billing->notify($s, 'payment_failed', 'failure:'.$key);
                }
            } elseif ($s->status !== 'cancelled' && ! in_array($s->status, ['expired'], true)) {
                $s->update(['cancel_at_period_end' => true, 'gateway_status' => 'cancelled']);
                $this->billing->event($s,'provider_cancelled');
            }
        }, 3);
    }
}
