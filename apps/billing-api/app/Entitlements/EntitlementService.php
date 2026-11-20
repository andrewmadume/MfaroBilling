<?php

namespace App\Entitlements;

use App\Models\Subscription;

class EntitlementService
{
    public function effective(string $org, string $product): array
    {
        $subs = Subscription::where('organisation_id', $org)->whereIn('status', ['active', 'trialing', 'past_due', 'grace_period'])->where(function ($q) {
            $q->where('period_end', '>', now())->orWhere('grace_ends_at', '>', now());
        })->get();
        $features = [];
        $limits = [];
        $sources = [];
        $until = null;
        $plan = null;
        $status = null;
        foreach ($subs as $s) {
            foreach ($s->snapshot['items'] as $item) {
                if ($item['product_code'] !== $product) {
                    continue;
                }
                $expiry = $s->grace_ends_at && $s->grace_ends_at->gt($s->period_end) ? $s->grace_ends_at : $s->period_end;
                if (! $expiry || $expiry->isPast()) {
                    continue;
                }
                foreach ($item['features'] as $k => $v) {
                    $features[$k] = ($features[$k] ?? false) || (bool) $v;
                }
                foreach ($item['limits'] as $k => $v) {
                    if (! array_key_exists($k, $limits)) {
                        $limits[$k] = $v;
                    } elseif ($v === null || $limits[$k] === null) {
                        $limits[$k] = null;
                    } else {
                        $limits[$k] = max($limits[$k], $v);
                    }
                }
                $sources[] = $s->id;
                if (! $until || $expiry->gt($until)) {
                    $until = $expiry;
                    $plan = $item['plan'];
                    $status = $s->status;
                }
            }
        }

        return ['version' => '1.0', 'organisation_id' => $org, 'product' => $product, 'allowed' => count($sources) > 0, 'subscription_status' => $status, 'plan' => $plan, 'features' => $features ?: new \stdClass, 'limits' => $limits ?: new \stdClass, 'valid_until' => $until?->toIso8601String(), 'source_subscription_ids' => $sources, 'cache_ttl_seconds' => count($sources) ? min(30, max(0, (int) now()->diffInSeconds($until))) : 0, 'checked_at' => now()->toIso8601String()];
    }
}
