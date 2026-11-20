<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController
{
    private const TABLES = ['customers' => 'users', 'organisations' => 'organisations', 'products' => 'products', 'plans' => 'plans', 'bundles' => 'bundles', 'subscriptions' => 'subscriptions', 'payments' => 'payments', 'invoices' => 'invoices', 'coupons' => 'coupons', 'audit-logs' => 'audit_logs', 'support' => 'support_tickets', 'api-clients' => 'api_clients', 'notifications' => 'outbox_events'];

    public function dashboard(Request $r)
    {
        $currency = $r->query('currency', 'ZAR');
        $s = Subscription::where('currency', $currency)->whereIn('status', ['active', 'past_due', 'grace_period'])->get();
        $mrr = $s->sum(fn ($x) => Money::minor($x->snapshot['subtotal']) / ($x->interval === 'annual' ? 12 : 1));
        $p = Payment::where('currency', $currency)->where('status', 'completed')->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())->get();
        $series = [];
        for ($n = 5; $n >= 0; $n--) {
            $d = now()->subMonthsNoOverflow($n);
            $series[] = ['month' => $d->format('M'), 'collected' => $p->filter(fn ($x) => substr($x->paid_at, 0, 7) === $d->format('Y-m'))->sum(fn ($x) => Money::minor($x->amount)) / 100];
        }

        return ['currency' => $currency, 'customers' => DB::table('users')->count(), 'organisations' => DB::table('organisations')->count(), 'active_subscriptions' => $s->count(), 'trial_subscriptions' => Subscription::where('status', 'trialing')->count(), 'mrr' => Money::decimal((int) round($mrr)), 'arr' => Money::decimal((int) round($mrr * 12)), 'cash_collected' => Money::decimal((int) $p->sum(fn ($x) => Money::minor($x->amount))), 'arpa' => Money::decimal($s->isEmpty() ? 0 : (int) round($mrr / max(1, $s->pluck('organisation_id')->unique()->count()))), 'revenue_series' => $series, 'recent_payments' => Payment::latest()->limit(8)->get()];
    }

    public function index(Request $r, string $resource)
    {
        abort_unless(isset(self::TABLES[$resource]), 404);
        $q = DB::table(self::TABLES[$resource]);
        $columns = $resource === 'customers' ? ['id', 'name', 'email', 'is_admin', 'created_at'] : ['*'];
        if ($r->filled('search')) {
            $col = match ($resource) {
                'customers' => 'email','products','plans','bundles','organisations' => 'name','invoices' => 'number','subscriptions','payments' => 'organisation_id','coupons' => 'code',default => 'id'
            };
            $q->where($col, 'like', '%'.substr($r->query('search'), 0, 100).'%');
        }

return $q->orderByDesc('created_at')->paginate(20, $columns);
    }

    private function audit(Request $r, string $action, array $payload): void
    {
        DB::table('audit_logs')->insert(['id' => Str::uuid(), 'user_id' => $r->user()->id, 'action' => $action, 'payload' => json_encode($payload), 'ip_address' => $r->ip(), 'created_at' => now()]);
    }

    public function save(Request $r, string $resource, ?string $id = null)
    {
        abort_unless(in_array($resource, ['products', 'plans', 'bundles', 'coupons'], true), 405, 'This record must be managed through a domain operation.');

        return DB::transaction(function () use ($r, $resource, $id) {
            if ($resource === 'products') {
                $d = $r->validate(['code' => 'required|alpha_dash|max:60', 'name' => 'required|string|max:160', 'description' => 'required|string|max:3000', 'website_url' => 'nullable|url:https|max:250', 'icon' => 'sometimes|string|max:60', 'features' => 'required|array', 'status' => 'required|in:active,retired']);
                $entity = $id ? Product::findOrFail($id) : new Product;
            } elseif ($resource === 'plans') {
                $d = $r->validate(['product_id' => 'required|uuid|exists:products,id', 'code' => 'required|alpha_dash|max:60', 'name' => 'required|string|max:160', 'description' => 'nullable|string|max:3000', 'features' => 'required|array', 'features.*' => 'boolean', 'limits' => 'required|array', 'limits.*' => 'nullable|integer|min:0', 'trial_days' => 'required|integer|min:0|max:90', 'status' => 'required|in:active,retired', 'monthly_price' => 'required|regex:/^\d{1,10}\.\d{2}$/', 'annual_price' => 'required|regex:/^\d{1,10}\.\d{2}$/']);
                $entity = $id ? Plan::findOrFail($id) : new Plan;
            } elseif ($resource === 'bundles') {
                $d = $r->validate(['code' => 'required|alpha_dash|max:60', 'name' => 'required|string|max:160', 'description' => 'required|string|max:3000', 'trial_days' => 'required|integer|min:0|max:90', 'status' => 'required|in:active,retired', 'plan_ids' => 'required|array|min:1', 'plan_ids.*' => 'required|uuid|distinct|exists:plans,id', 'monthly_price' => 'required|regex:/^\d{1,10}\.\d{2}$/', 'annual_price' => 'required|regex:/^\d{1,10}\.\d{2}$/']);
                $entity = $id ? Bundle::findOrFail($id) : new Bundle;
                $plans = Plan::whereIn('id', $d['plan_ids'])->get();
                abort_unless($plans->pluck('product_id')->unique()->count() === $plans->count(), 422, 'Only one plan per product is allowed in a bundle.');
            } else {
                $d = $r->validate(['code' => 'required|alpha_dash|max:60', 'percent' => 'required|integer|min:1|max:90', 'expires_at' => 'nullable|date', 'max_redemptions' => 'nullable|integer|min:1', 'active' => 'required|boolean']);
                if ($id) {
                    abort_unless(DB::table('coupons')->where('id', $id)->exists(), 404);
                    DB::table('coupons')->where('id', $id)->update($d + ['updated_at' => now()]);
                } else {
                    $id = (string) Str::uuid();
                    DB::table('coupons')->insert($d + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }$this->audit($r, 'coupon.saved', ['id' => $id]);

                return DB::table('coupons')->where('id', $id)->first();
            }
            $prices = [$d['monthly_price'] ?? null, $d['annual_price'] ?? null];
            $planIds = $d['plan_ids'] ?? [];
            unset($d['monthly_price'],$d['annual_price'],$d['plan_ids']);
            $entity->fill($d)->save();
            if ($resource === 'bundles') {
                DB::table('bundle_items')->where('bundle_id', $entity->id)->delete();
                foreach ($planIds as $p) {
                    DB::table('bundle_items')->insert(['bundle_id' => $entity->id, 'plan_id' => $p]);
                }
            }
            if (in_array($resource, ['plans', 'bundles'], true)) {
                $key = $resource === 'plans' ? 'plan_id' : 'bundle_id';
                $o = Offer::updateOrCreate([$key => $entity->id], ['name' => $resource === 'plans' ? Product::findOrFail($entity->product_id)->name.' '.$entity->name : $entity->name, 'status' => $entity->status]);
                foreach (['monthly', 'annual'] as $n => $interval) {
                    $p = OfferPrice::updateOrCreate(['offer_id' => $o->id, 'interval' => $interval, 'currency' => 'ZAR'], ['amount' => $prices[$n]]);
                    DB::table('price_versions')->insert(['id' => Str::uuid(), 'offer_price_id' => $p->id, 'amount' => $p->amount, 'currency' => $p->currency, 'effective_at' => now()]);
                }
            }
            $this->audit($r, $resource.'.saved', ['id' => $entity->id]);

            return $entity;
        });
    }

    public function retire(Request $r, string $resource, string $id)
    {
        abort_unless(in_array($resource, ['products', 'plans', 'bundles'], true), 405);
        abort_unless(DB::table($resource)->where('id', $id)->update(['status' => 'retired', 'updated_at' => now()]), 404);
        if ($resource !== 'products') {
            DB::table('offers')->where($resource === 'plans' ? 'plan_id' : 'bundle_id', $id)->update(['status' => 'retired']);
        }$this->audit($r, $resource.'.retired', ['id' => $id]);

        return ['message' => 'Retired. Existing agreements are preserved.'];
    }

    public function settings(Request $r)
    {
        $data = DB::table('system_settings')->get()->mapWithKeys(fn ($v) => [$v->key, json_decode(Crypt::decryptString($v->value), true)]);

        return ['settings' => $data, 'deployment' => ['payfast_enabled' => config('billing.payfast.enabled'), 'sandbox' => config('billing.payfast.sandbox'), 'oidc_configured' => (bool) config('billing.oidc.issuer'), 'tax_basis_points' => config('billing.tax_basis_points'), 'grace_days' => config('billing.grace_days')]];
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['support_email' => 'required|email', 'privacy_url' => 'nullable|url:https', 'terms_url' => 'nullable|url:https', 'retention_days' => 'required|integer|min:30|max:36500']);
        foreach ($d as $k => $v) {
            DB::table('system_settings')->updateOrInsert(['key' => $k], ['value' => Crypt::encryptString(json_encode($v)), 'created_at' => now(), 'updated_at' => now()]);
        }$this->audit($r, 'settings.updated', ['keys' => array_keys($d)]);

        return ['message' => 'Settings saved.'];
    }

    public function credential(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'product_code' => 'required|exists:products,code', 'organisation_ids' => 'required|array|min:1', 'organisation_ids.*' => 'uuid|exists:organisations,id']);
        $token = Str::random(64);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($r, $d, $token, $id) {
            DB::table('api_clients')->insert(['id' => $id, 'name' => $d['name'], 'product_code' => $d['product_code'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('api_client_credentials')->insert(['id' => Str::uuid(), 'api_client_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(90), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_unique($d['organisation_ids']) as $org) {
                DB::table('product_organisation_access')->insert(['api_client_id' => $id, 'organisation_id' => $org]);
            }$this->audit($r, 'api_client.created', ['id' => $id]);
        });

        return response()->json(['client_id' => $id, 'token' => $token, 'expires_at' => now()->addDays(90), 'message' => 'Store securely. This token is displayed once.'], 201);
    }

    public function revoke(Request $r, string $id)
    {
        DB::table('api_client_credentials')->where('api_client_id', $id)->update(['revoked_at' => now()]);
        $this->audit($r, 'api_client.revoked', ['id' => $id]);

        return ['message' => 'Credentials revoked.'];
    }

    public function refund(Request $r, string $id)
    {
        $d = $r->validate(['amount' => 'required|regex:/^\d{1,10}\.\d{2}$/', 'reference' => 'required|string|max:120', 'reason' => 'required|string|max:500']);

        return DB::transaction(function () use ($r, $id, $d) {
            $p = Payment::whereKey($id)->lockForUpdate()->firstOrFail();
            $refunded = DB::table('payment_refunds')->where('payment_id', $id)->get()->sum(fn ($x) => Money::minor($x->amount));
            abort_if($refunded + Money::minor($d['amount']) > Money::minor($p->amount), 422, 'Refund exceeds payment.');
            DB::table('payment_refunds')->insert($d + ['id' => Str::uuid(), 'payment_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'refund.recorded', ['payment_id' => $id, 'reference' => $d['reference']]);

            return ['message' => 'Externally completed refund recorded. This does not send a refund to PayFast.'];
        });
    }

    public function csv(Request $r)
    {
        $currency = $r->validate(['currency' => 'required|string|size:3'])['currency'];

        return response()->streamDownload(function () use ($currency) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Organisation', 'Currency', 'Amount', 'Status', 'Paid at'], ',', '"', '');
            Payment::where('currency', $currency)->orderBy('id')->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out,[$p->provider_reference, $p->organisation_id, $p->currency, $p->amount, $p->status, $p->paid_at],',','"','');
                }
            });
            fclose($out);
        }, 'mfaro-payments.csv', ['Content-Type' => 'text/csv']);
    }
}
