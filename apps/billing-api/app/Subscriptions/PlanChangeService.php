<?php

namespace App\Subscriptions;

use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\Subscription;
use App\Payments\PayFastGateway;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanChangeService
{
    public function __construct(private BillingService $billing, private PayFastGateway $gateway) {}

    public function quote(Subscription $s, string $priceId): array
    {
        abort_unless($s->status === 'active' && $s->gateway_token && ! $s->cancel_at_period_end, 409, 'Only active provider subscriptions can change plans.');
        abort_unless($s->period_end && $s->period_end->gt(now()->addDay()), 409, 'Changes require at least 24 hours before renewal.');
        $price = OfferPrice::findOrFail($priceId);
        $offer = Offer::where('status', 'active')->findOrFail($price->offer_id);
        abort_unless($price->currency === $s->currency && $price->currency === 'ZAR', 422, 'Currency cannot change.');
        $items = $this->billing->plans($offer)->map(function ($p) {
            $product = Product::where('status', 'active')->findOrFail($p->product_id);
            abort_unless($p->status === 'active', 422);

            return ['plan_id' => $p->id, 'product_code' => $product->code, 'product_name' => $product->name, 'plan' => $p->code, 'features' => $p->features, 'limits' => $p->limits];
        })->all();
        abort_if(! $items, 422, 'Offer has no products.');
        foreach (Subscription::where('organisation_id', $s->organisation_id)->where('id', '!=', $s->id)->whereIn('status', ['active', 'pending', 'trialing', 'past_due', 'grace_period', 'suspended'])->get() as $other) {
            abort_if(count(array_intersect(array_column($items, 'product_code'), array_column($other->snapshot['items'], 'product_code'))) > 0, 409, 'The target overlaps another agreement. Cancel or migrate that agreement separately first.');
        }
        $subtotal = Money::minor($price->amount);
        $tax = Money::tax($subtotal, config('billing.tax_basis_points'));
        abort_if($subtotal < 500, 422);
        $quote = ['offer_id' => $offer->id, 'offer_price_id' => $price->id, 'effective_at' => $s->period_end->toIso8601String(), 'amount' => Money::decimal($subtotal + $tax), 'currency' => $price->currency, 'interval' => $price->interval, 'due_today' => '0.00', 'proration_policy' => 'period_end', 'snapshot' => ['name' => $offer->name, 'items' => $items, 'subtotal' => Money::decimal($subtotal), 'tax' => Money::decimal($tax), 'tax_basis_points' => config('billing.tax_basis_points'), 'catalogue_amount' => $price->amount, 'discount_percent' => 0, 'coupon' => null, 'interval' => $price->interval, 'currency' => $price->currency]];
        $quote['quote_hash'] = hash('sha256', json_encode($quote));

        return $quote;
    }

    public function schedule(Subscription $s, string $priceId, string $key, string $quoteHash): array
    {
        $change = DB::transaction(function () use ($s, $priceId, $key, $quoteHash) {
            Organisation::whereKey($s->organisation_id)->lockForUpdate()->firstOrFail();
            $s = Subscription::whereKey($s->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('subscription_changes')->where('organisation_id', $s->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->subscription_id === $s->id && $existing->offer_price_id === $priceId && hash_equals($existing->quote_hash ?? '', $quoteHash), 409, 'Idempotency key conflict.');

                return (array) $existing + ['_new' => false];
            }
            abort_if(DB::table('subscription_changes')->where('subscription_id', $s->id)->whereIn('status', ['applying', 'scheduled', 'needs_reconciliation'])->exists(), 409, 'A plan change is already pending.');
            $q = $this->quote($s, $priceId);
            abort_unless(hash_equals($q['quote_hash'], $quoteHash), 409, 'The quote changed. Review a fresh quote before confirming.');
            $row = ['id' => (string) Str::uuid(), 'organisation_id' => $s->organisation_id, 'subscription_id' => $s->id, 'offer_price_id' => $priceId, 'idempotency_key' => $key, 'quote_hash' => $quoteHash, 'status' => 'applying', 'amount' => $q['amount'], 'snapshot' => json_encode($q['snapshot']), 'effective_at' => $s->period_end, 'created_at' => now(), 'updated_at' => now()];
            DB::table('subscription_changes')->insert($row);

            return $row + ['_new' => true];
        });
        if (! ($change['_new'] ?? false)) {
            return $change;
        }unset($change['_new']);
        // The durable intent exists before the external mutation. A timeout is marked for reconciliation,
        // never automatically retried, because the provider may already have applied it.
        try {
            $snapshot = json_decode($change['snapshot'], true);
            $this->gateway->update($s->gateway_token, ['amount' => Money::minor($change['amount']), 'frequency' => $snapshot['interval'] === 'annual' ? 6 : 3, 'run_date' => $s->period_end->setTimezone('Africa/Johannesburg')->format('Y-m-d')]);
            DB::table('subscription_changes')->where('id', $change['id'])->update(['status' => 'scheduled', 'updated_at' => now()]);
            $this->billing->event($s, 'plan_change_scheduled', ['change_id' => $change['id']]);
            $this->billing->notify($s, 'subscription_change_scheduled', 'change:'.$change['id']);
            $change['status'] = 'scheduled';

            return $change;
        } catch (\Throwable $e) {
            DB::table('subscription_changes')->where('id',$change['id'])->update(['status' => 'needs_reconciliation', 'updated_at' => now()]);
            throw $e;
        }
    }
}
