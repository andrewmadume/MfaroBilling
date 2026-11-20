<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Subscriptions\PlanChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanChangeController
{
    private function subscription(Request $r, string $id): Subscription
    {
        OrganisationController::permit($r, ['owner', 'administrator', 'billing_manager']);

        return Subscription::where('organisation_id', $r->attributes->get('organisation_id'))->findOrFail($id);
    }

    public function preview(Request $r, string $id, PlanChangeService $service)
    {
        $d = $r->validate(['offer_price_id' => 'required|uuid']);

        return $service->quote($this->subscription($r, $id), $d['offer_price_id']);
    }

    public function schedule(Request $r, string $id, PlanChangeService $service)
    {
        $d = $r->validate(['offer_price_id' => 'required|uuid', 'quote_hash' => 'required|string|size:64']);
        $key = $r->header('Idempotency-Key');
        abort_unless($key && strlen($key) <= 120, 422, 'Idempotency-Key required.');

        return $service->schedule($this->subscription($r, $id), $d['offer_price_id'], $key, $d['quote_hash']);
    }

    public function history(Request $r, string $id)
    {
        $s = $this->subscription($r, $id);

        return DB::table('subscription_changes')->where('subscription_id', $s->id)->latest()->get();
    }
}
