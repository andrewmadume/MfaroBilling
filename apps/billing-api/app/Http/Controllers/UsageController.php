<?php

namespace App\Http\Controllers;

use App\Entitlements\EntitlementService;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageController
{
    public function consume(Request $r, EntitlementService $entitlements)
    {
        $d = $r->validate(['metric' => 'required|in:transactions,storage_bytes', 'quantity' => 'required|integer|min:1|max:1000000000', 'event_key' => 'required|string|max:100']);
        $org = $r->input('organisation_id');
        $product = $r->input('product');
        $client = $r->attributes->get('product_client');

        return DB::transaction(function () use ($d, $org, $product, $client, $entitlements) {
            Organisation::whereKey($org)->lockForUpdate()->firstOrFail();
            $e = $entitlements->effective($org, $product);
            abort_unless($e['allowed'], 403, 'Product access is not active.');
            $key = hash('sha256', $client->id.':'.$org.':'.$d['event_key']);
            $old = DB::table('usage_records')->where('event_key', $key)->first();
            if ($old) {
                abort_unless($old->metric === $d['metric'] && (int) $old->quantity === $d['quantity'], 409, 'Usage key reused with another payload.');

                return ['recorded' => true, 'replayed' => true];
            }$q = DB::table('usage_records')->where('organisation_id', $org)->where('product_code', $product)->where('metric', $d['metric']);
            if ($d['metric'] === 'transactions') {
                $q->where('recorded_at', '>=', now()->startOfMonth());
            }$used = (int) $q->sum('quantity');
            $limits = (array) $e['limits'];
            $limit = $limits['max_'.$d['metric']] ?? null;
            abort_if($limit !== null && $used + $d['quantity'] > $limit, 409, 'Usage limit reached.');
            DB::table('usage_records')->insert(['id' => Str::uuid(), 'organisation_id' => $org, 'product_code' => $product, 'metric' => $d['metric'], 'quantity' => $d['quantity'], 'event_key' => $key, 'recorded_at' => now()]);

            return ['recorded' => true, 'used' => $used + $d['quantity'], 'limit' => $limit];
        }, 3);
    }
}
