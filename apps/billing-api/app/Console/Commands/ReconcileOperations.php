<?php

namespace App\Console\Commands;

use App\Payments\GatewayOperationService;
use App\Payments\RefundService;
use App\Subscriptions\LifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReconcileOperations extends Command
{
    protected $signature = 'billing:reconcile-operations';

    protected $description = 'Resolve uncertain provider state with read-only checks; never retry mutations.';

    public function handle(LifecycleService $lifecycle, RefundService $refunds): int
    {
        if (! config('billing.payfast.enabled')) {
            return 0;
        }

return Cache::lock('billing-operation-reconcile', 240)->get(function () use ($lifecycle, $refunds) {
            DB::table('gateway_operations')->whereIn('status', GatewayOperationService::UNRESOLVED)->where('updated_at', '<', now()->subMinutes(2))->orderBy('id')->chunk(50, function ($rows) use ($lifecycle, $refunds) {
                foreach ($rows as $row) {
                    try {
                        $row->kind === 'refund' ? $refunds->reconcile($row->id) : $lifecycle->reconcile($row->id);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

            return 0;
        }) ?? 0;
    }
}
