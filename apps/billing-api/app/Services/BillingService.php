<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Organisation;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Subscriptions\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function plans(Offer $offer)
    {
        return $offer->plan_id ? Plan::whereKey($offer->plan_id)->get() : Plan::whereIn('id', DB::table('bundle_items')->where('bundle_id', $offer->bundle_id)->pluck('plan_id'))->orderBy('id')->get();
    }

    public function create(string $org, array $data): Subscription
    {
        return DB::transaction(function () use ($org, $data) {
            Organisation::whereKey($org)->lockForUpdate()->firstOrFail();
            $price = OfferPrice::findOrFail($data['offer_price_id']);
            $offer = Offer::where('status', 'active')->findOrFail($price->offer_id);
            $plans = $this->plans($offer);
            abort_if($plans->isEmpty(), 422, 'Offer has no products.');
            $items = $plans->map(function ($p) {
                $product = Product::where('status', 'active')->findOrFail($p->product_id);
                abort_unless($p->status === 'active', 422, 'Plan is retired.');

                return ['plan_id' => $p->id, 'product_code' => $product->code, 'product_name' => $product->name, 'plan' => $p->code, 'features' => $p->features, 'limits' => $p->limits];
            })->all();
            $existing = Subscription::where('organisation_id', $org)->whereIn('status', ['pending', 'active', 'trialing', 'past_due', 'grace_period', 'suspended'])->get();
            $overlap = $existing->contains(fn ($s) => count(array_intersect(array_column($items, 'product_code'), array_column($s->snapshot['items'], 'product_code'))) > 0);
            abort_if($overlap && ! ($data['accept_overlap'] ?? false), 409, 'This organisation already has coverage. Explicitly accept overlap to continue.');
            $percent = 0;
            $coupon = null;
            if (! empty($data['coupon'])) {
                $coupon = DB::table('coupons')->where('code', strtoupper($data['coupon']))->lockForUpdate()->first();
                abort_unless($coupon && $coupon->active && (! $coupon->expires_at || now()->lt($coupon->expires_at)) && (! $coupon->max_redemptions || $coupon->redemptions < $coupon->max_redemptions), 422, 'Coupon is invalid or exhausted.');
                $percent = $coupon->percent;
            }
            $amount = Money::discount(Money::minor($price->amount), $percent);
            $tax = Money::tax($amount, config('billing.tax_basis_points'));
            $total = Money::decimal($amount + $tax);
            abort_unless($price->currency === 'ZAR', 422, 'PayFast checkout supports ZAR only.');
            abort_if($amount < 500, 422, 'Recurring checkout requires a minimum of R5.00.');
            $s = Subscription::create(['organisation_id' => $org, 'offer_id' => $offer->id, 'offer_price_id' => $price->id, 'status' => 'pending', 'interval' => $price->interval, 'currency' => $price->currency, 'amount' => $total, 'snapshot' => ['name' => $offer->name, 'items' => $items, 'subtotal' => Money::decimal($amount), 'tax' => Money::decimal($tax), 'tax_basis_points' => config('billing.tax_basis_points'), 'catalogue_amount' => $price->amount, 'discount_percent' => $percent, 'coupon' => $coupon?->code, 'interval' => $price->interval, 'currency' => $price->currency]]);
            foreach ($items as $item) {
                DB::table('subscription_items')->insert(['id' => Str::uuid(), 'subscription_id' => $s->id, 'plan_id' => $item['plan_id'], 'product_code' => $item['product_code'], 'snapshot' => json_encode($item), 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($coupon) {
                DB::table('coupons')->where('id', $coupon->id)->increment('redemptions');
            }
            $this->event($s, 'created', ['overlap_accepted' => $overlap]);
            $this->invoice($s);

            return $s;
        });
    }

    public function invoice(Subscription $s): Invoice
    {
        $org = Organisation::findOrFail($s->organisation_id);
        $i = Invoice::create(['organisation_id' => $org->id, 'subscription_id' => $s->id, 'number' => 'MF-'.now()->format('Y').'-'.strtoupper(Str::random(12)), 'status' => 'open', 'currency' => $s->currency, 'subtotal' => $s->snapshot['subtotal'], 'tax' => $s->snapshot['tax'], 'total' => $s->amount, 'customer_snapshot' => json_encode($org->toArray()), 'seller_snapshot' => json_encode(['name' => config('billing.company_name'), 'address' => config('billing.company_address'), 'vat_number' => config('billing.vat_number')]), 'issued_at' => now(), 'due_at' => now()->addDays(7)]);
        DB::table('invoice_items')->insert(['id' => Str::uuid(), 'invoice_id' => $i->id, 'description' => $s->snapshot['name'].' · '.$s->interval, 'quantity' => 1, 'unit_price' => $s->snapshot['subtotal'], 'total' => $s->snapshot['subtotal']]);

        return $i;
    }

    public function event(Subscription $s, string $type, array $payload = []): void
    {
        DB::table('subscription_events')->insert(['id' => Str::uuid(), 'subscription_id' => $s->id, 'type' => $type, 'payload' => json_encode($payload), 'created_at' => now()]);
    }

    public function transition(Subscription $s, string $to): void
    {
        StateMachine::assert($s->status, $to);
        $from = $s->status;
        $s->update(['status' => $to]);
        $this->event($s, 'status_changed', ['from' => $from, 'to' => $to]);
    }

    public function grant(Subscription $s): void
    {
        foreach ($s->snapshot['items'] as $item) {
            DB::table('entitlement_grants')->updateOrInsert(['subscription_id' => $s->id, 'product_code' => $item['product_code']], ['id' => (string) Str::uuid(), 'organisation_id' => $s->organisation_id, 'features' => json_encode($item['features']), 'limits' => json_encode($item['limits']), 'valid_until' => $s->period_end, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function notify(Subscription $s, string $type, string $key, array $payload = []): void
    {
        $org = Organisation::findOrFail($s->organisation_id);
        $inserted = DB::table('outbox_events')->insertOrIgnore(['id' => Str::uuid(), 'organisation_id' => $org->id, 'type' => $type, 'recipient' => $org->billing_email, 'payload' => json_encode(array_merge(['organisation' => $org->name, 'subscription' => $s->snapshot['name'], 'amount' => $s->amount, 'currency' => $s->currency, 'portal_url' => config('billing.portal_url')], $payload)), 'deduplication_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            DB::table('notifications')->insert(['id' => Str::uuid(), 'organisation_id' => $org->id, 'title' => ucwords(str_replace('_', ' ', $type)), 'message' => 'Review your subscription and billing records for this update.', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
