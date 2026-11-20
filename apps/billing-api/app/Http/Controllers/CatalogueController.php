<?php

namespace App\Http\Controllers;

use App\Models\Bundle;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Plan;
use App\Models\Product;
use App\Services\BillingService;

class CatalogueController
{
    public function products()
    {
        return Product::where('status', 'active')->get()->map(function ($p) {
            $p->plans = Plan::where('product_id', $p->id)->where('status', 'active')->orderBy('display_order')->get();

            return $p;
        });
    }

    public function offers(BillingService $b)
    {
        return Offer::where('status', 'active')->get()->map(function ($o) use ($b) {
            $o->prices = OfferPrice::where('offer_id', $o->id)->get();
            $o->items = $b->plans($o)->map(function ($p) {
                $p->product = Product::find($p->product_id);

                return $p;
            });
            $o->kind = $o->bundle_id ? 'bundle' : 'plan';
            $o->trial_days = $o->bundle_id ? (Bundle::find($o->bundle_id)?->trial_days ?? 0) : (Plan::find($o->plan_id)?->trial_days ?? 0);
            $o->description = $o->bundle_id ? Bundle::find($o->bundle_id)?->description : Plan::find($o->plan_id)?->description;

            return $o;
        });
    }
}
