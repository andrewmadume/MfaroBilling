<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefundRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\RefundService;
use App\Subscriptions\LifecycleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController
{
    public function quote(RefundRequest $r, string $id, RefundService $refunds): array
    {
        $d = $r->validated();

        return $refunds->quote(Payment::findOrFail($id), $d['amount'], $d['reason']);
    }

    public function issue(RefundRequest $r, string $id, RefundService $refunds): JsonResponse
    {
        $d = $r->validated();
        abort_unless(isset($d['quote_hash']), 422, 'quote_hash is required to confirm the refund.');
        $key = $r->header('Idempotency-Key');
        abort_unless($key && strlen($key) <= 120, 422, 'Idempotency-Key required.');
        $op = $refunds->issue(Payment::findOrFail($id), $d, $key, $r->user()->id);

        return response()->json($op, $op['status'] === 'succeeded' ? 200 : 202);
    }

    public function operations(Request $r): mixed
    {
        return DB::table('gateway_operations')->orderByDesc('created_at')->paginate(20, ['id', 'organisation_id', 'subscription_id', 'payment_id', 'kind', 'status', 'failure_code', 'created_at', 'completed_at']);
    }

    public function reconcile(Request $r, string $id, LifecycleService $lifecycle, RefundService $refunds): array
    {
        $op = DB::table('gateway_operations')->find($id);
        abort_unless($op, 404);

        return $op->kind === 'refund' ? $refunds->reconcile($id) : $lifecycle->reconcile($id);
    }

    public function resolve(Request $r, string $id, RefundService $refunds, LifecycleService $lifecycle): array
    {
        $d = $r->validate(['outcome' => 'required|in:confirmed_processed,confirmed_not_processed', 'evidence_reference' => 'required|string|min:6|max:255', 'attested' => 'required|accepted']);
        $op = DB::table('gateway_operations')->find($id);
        abort_unless($op, 404);

        return $op->kind === 'refund' ? $refunds->resolve($id, $r->user()->id, $d['evidence_reference'], $d['outcome']) : $lifecycle->resolve($id, $r->user()->id, $d['evidence_reference'], $d['outcome']);
    }

    public function notes(Request $r): mixed
    {
        OrganisationController::permit($r, ['owner', 'administrator', 'billing_manager']);

        return DB::table('credit_notes')->where('organisation_id', $r->attributes->get('organisation_id'))->orderByDesc('created_at')->paginate(20);
    }

    public function download(Request $r, string $id): mixed
    {
        OrganisationController::permit($r, ['owner', 'administrator', 'billing_manager']);
        $note = DB::table('credit_notes')->where('organisation_id', $r->attributes->get('organisation_id'))->where('id', $id)->first();
        abort_unless($note, 404);
        $invoice = Invoice::findOrFail($note->invoice_id);

        return Pdf::loadView('credit-note', ['note' => $note, 'invoice' => $invoice, 'customer' => json_decode($invoice->customer_snapshot, true), 'seller' => json_decode($invoice->seller_snapshot,true)])->setOption('isRemoteEnabled',false)->download($note->number.'.pdf');
    }
}
