<?php

namespace App\Http\Controllers;

use App\Entitlements\EntitlementService;
use App\Models\Bundle;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Payments\PayFastGateway;
use App\Payments\PaymentProcessor;
use App\Services\BillingService;
use App\Services\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortalController
{
    private function org(Request $r): string
    {
        return $r->attributes->get('organisation_id');
    }

    private function billing(Request $r): void
    {
        OrganisationController::permit($r, ['owner', 'administrator', 'billing_manager']);
    }

    private function subscription(Request $r, string $id): Subscription
    {
        return Subscription::where('organisation_id', $this->org($r))->findOrFail($id);
    }

    public function dashboard(Request $r, EntitlementService $e)
    {
        $finance = in_array($r->attributes->get('organisation_role'), ['owner', 'administrator', 'billing_manager'], true);
        $org = $this->org($r);
        $s = Subscription::where('organisation_id', $org)->get();
        $visible = $s->whereIn('status', ['active', 'trialing', 'past_due', 'grace_period']);
        $mrr = $visible->where('status', '!=', 'trialing')->sum(fn ($x) => Money::minor($x->snapshot['subtotal']) / ($x->interval === 'annual' ? 12 : 1));
        $allowed = [];
        foreach (Product::where('status', 'active')->get() as $p) {
            $ent = $e->effective($org, $p->code);
            if ($ent['allowed']) {
                $allowed[] = array_merge($p->toArray(), ['entitlement' => $ent]);
            }
        }

        return ['organisation' => Organisation::findOrFail($org), 'active_subscriptions' => $visible->count(), 'financial_visible' => $finance, 'monthly_total' => $finance ? Money::decimal((int) round($mrr)) : null, 'currency' => 'ZAR', 'next_payment' => $visible->whereNotNull('period_end')->sortBy('period_end')->first()?->period_end, 'outstanding' => $finance ? Money::decimal((int) Invoice::where('organisation_id', $org)->where('status', 'open')->get()->sum(fn ($i) => Money::minor($i->total))) : null, 'recent_payments' => $finance ? Payment::where('organisation_id', $org)->latest()->limit(5)->get() : [], 'applications' => $allowed, 'notifications' => DB::table('notifications')->where('organisation_id', $org)->latest()->limit(5)->get()];
    }

    public function subscriptions(Request $r)
    {
        $this->billing($r);

        return Subscription::where('organisation_id', $this->org($r))->latest()->paginate(min(100, max(1, (int) $r->query('per_page', 20))));
    }

    public function create(Request $r, BillingService $b)
    {
        $this->billing($r);
        $d = $r->validate(['offer_price_id' => 'required|uuid', 'accept_overlap' => 'sometimes|boolean', 'coupon' => 'nullable|string|max:80']);
        $key = $r->header('Idempotency-Key');
        abort_unless($key && strlen($key) <= 120, 422, 'Idempotency-Key header required.');
        $org = $this->org($r);
        $hash = hash('sha256', json_encode($d));
        $result = DB::transaction(function () use ($b, $d, $key, $org, $hash) {
            Organisation::whereKey($org)->lockForUpdate()->firstOrFail();
            $old = DB::table('idempotency_keys')->where('organisation_id', $org)->where('key', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency key reused for different request.');

                return json_decode($old->response, true);
            }$s = $b->create($org, $d);
            $response = $s->toArray();
            DB::table('idempotency_keys')->insert(['organisation_id' => $org, 'key' => $key, 'request_hash' => $hash, 'response' => json_encode($response), 'created_at' => now(), 'updated_at' => now()]);

            return $response;
        }, 3);

        return response()->json($result, 201);
    }

    public function trial(Request $r, BillingService $b)
    {
        $this->billing($r);
        $d = $r->validate(['offer_price_id' => 'required|uuid']);
        $org = $this->org($r);
        $s = DB::transaction(function () use ($org, $d, $b) {
            Organisation::whereKey($org)->lockForUpdate()->firstOrFail();
            $p = OfferPrice::findOrFail($d['offer_price_id']);
            $o = Offer::findOrFail($p->offer_id);
            $days = $o->bundle_id ? Bundle::findOrFail($o->bundle_id)->trial_days : Plan::findOrFail($o->plan_id)->trial_days;
            abort_unless($days > 0, 422, 'No trial is configured.');
            abort_if(Subscription::where('organisation_id', $org)->where('offer_id', $o->id)->exists(), 409, 'A trial or subscription was already created for this offer.');
            $s = $b->create($org, $d);
            $b->transition($s, 'trialing');
            $s->update(['period_start' => now(), 'period_end' => now()->addDays($days), 'trial_ends_at' => now()->addDays($days)]);
            Invoice::where('subscription_id', $s->id)->where('status', 'open')->update(['status' => 'void']);
            $b->grant($s);
            $b->notify($s, 'trial_started', 'trial:'.$s->id);

            return $s;
        });

        return response()->json($s, 201);
    }

    public function detail(Request $r, string $id)
    {
        $this->billing($r);
        $s = $this->subscription($r, $id);

        return ['subscription' => $s, 'events' => DB::table('subscription_events')->where('subscription_id', $s->id)->orderByDesc('created_at')->get()];
    }

    public function checkout(Request $r, PayFastGateway $g, BillingService $b)
    {
        $this->billing($r);
        $d = $r->validate(['subscription_id' => 'required|uuid']);
        $s = $this->subscription($r, $d['subscription_id']);

        return DB::transaction(function () use ($s, $g, $b) {
            $s = Subscription::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'trialing') {
                $b->transition($s, 'pending');
                $s->update(['period_start' => null, 'period_end' => null]);
            }abort_unless($s->status === 'pending', 409, 'Checkout requires a pending subscription.');
            $i = Invoice::where('subscription_id', $s->id)->where('status', 'open')->first() ?? $b->invoice($s);
            abort_if(DB::table('payment_attempts')->where('subscription_id', $s->id)->where('status', 'pending')->exists(), 409, 'A checkout is already in progress. Wait for confirmation or contact support before creating another.');
            $checkout = $g->checkout($s, $i);
            DB::table('payment_attempts')->insert(['id' => Str::uuid(), 'organisation_id' => $s->organisation_id, 'subscription_id' => $s->id, 'invoice_id' => $i->id, 'status' => 'pending', 'amount' => $s->amount, 'currency' => $s->currency, 'created_at' => now(), 'updated_at' => now()]);

            return $checkout;
        });
    }

    public function cancel(Request $r, string $id, PayFastGateway $g, BillingService $b)
    {
        $this->billing($r);
        $s = $this->subscription($r, $id);
        $r->validate(['immediate' => 'sometimes|boolean']);
        // Provider operation is idempotent only through manual reconciliation on uncertainty; never retry an ambiguous charge.
        if ($s->gateway_token && $s->gateway_status !== 'cancelled') {
            $g->cancel($s->gateway_token);
            $s->update(['gateway_status' => 'cancelled']);
        }

        return DB::transaction(function () use ($s, $r, $b) {
            $s = Subscription::whereKey($s->id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'cancelled') {
                return $s;
            }if ($r->boolean('immediate') || $s->status === 'pending') {
                $b->transition($s, 'cancelled');
            } else {
                $s->update(['cancel_at_period_end' => true]);
            }$b->event($s, 'cancellation_requested', ['immediate' => $r->boolean('immediate')]);
            $b->notify($s, 'subscription_cancelled', 'cancel:'.$s->id);

            return $s;
        });
    }

    public function invoices(Request $r)
    {
        $this->billing($r);
        $q = Invoice::where('organisation_id', $this->org($r));
        if ($r->filled('search')) {
            $q->where('number', 'like', '%'.substr($r->query('search'), 0, 100).'%');
        }if (in_array($r->query('status'), ['open', 'paid', 'void'])) {
            $q->where('status', $r->query('status'));
        }

return $q->latest()->paginate(20);
    }

    public function invoice(Request $r, string $id)
    {
        $this->billing($r);
        $i = Invoice::where('organisation_id', $this->org($r))->findOrFail($id);

        return ['invoice' => $i, 'items' => DB::table('invoice_items')->where('invoice_id', $id)->get()];
    }

    public function pdf(Request $r, string $id)
    {
        $this->billing($r);
        $i = Invoice::where('organisation_id', $this->org($r))->findOrFail($id);

        return Pdf::loadView('invoice', ['invoice' => $i, 'items' => DB::table('invoice_items')->where('invoice_id', $id)->get(), 'customer' => json_decode($i->customer_snapshot, true), 'seller' => json_decode($i->seller_snapshot, true)])->setOption('isRemoteEnabled', false)->download($i->number.'.pdf');
    }

    public function payments(Request $r)
    {
        $this->billing($r);

        return Payment::where('organisation_id', $this->org($r))->latest()->paginate(20);
    }

    public function entitlements(Request $r, EntitlementService $e)
    {
        return Product::where('status', 'active')->get()->map(fn ($p) => $e->effective($this->org($r), $p->code));
    }

    public function support(Request $r)
    {
        return SupportTicket::where('organisation_id', $this->org($r))->latest()->paginate(20);
    }

    public function ticket(Request $r)
    {
        $d = $r->validate(['subject' => 'required|string|max:160', 'message' => 'required|string|max:10000']);

        return response()->json(SupportTicket::create($d + ['organisation_id' => $this->org($r), 'user_id' => $r->user()->id]), 201);
    }

    public function paymentMethods(Request $r)
    {
        $this->billing($r);

        return Subscription::where('organisation_id', $this->org($r))->whereNotNull('gateway_token')->where('gateway_status', '!=', 'cancelled')->get()->map(fn ($s) => ['subscription_id' => $s->id, 'name' => $s->snapshot['name'], 'provider' => 'payfast', 'description' => 'Provider-managed recurring card']);
    }

    public function paymentMethodSetup(Request $r)
    {
        $this->billing($r);
        $d = $r->validate(['subscription_id' => 'required|uuid']);
        $s = $this->subscription($r, $d['subscription_id']);
        abort_unless($s->gateway_token && $s->gateway_status !== 'cancelled', 422, 'No active recurring payment method.');
        $base = config('billing.payfast.sandbox') ? 'https://sandbox.payfast.co.za' : 'https://www.payfast.co.za';

        return ['url' => $base.'/eng/recurring/update/'.rawurlencode($s->gateway_token).'?return='.rawurlencode(config('billing.portal_url').'/payments')];
    }

    public function webhook(Request $r,PayFastGateway $g,PaymentProcessor $p)
    {
        abort_if(strlen($r->getContent()) > 32768,413);
        $p->process($g->verify($r));

        return response('OK',200);
    }
}
