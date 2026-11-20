<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Payments\PayFastGateway;
use App\Services\BillingService;
use App\Services\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReconcileGateway extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Compare provider-managed schedules without initiating charges or fabricating payment confirmation.';

    public function handle(PayFastGateway $gateway, BillingService $b): int
    {
        if (! config('billing.payfast.enabled')) {
            $this->warn('PayFast is disabled.');

            return 0;
        }

return Cache::lock('billing-reconcile', 3500)->get(function () use ($gateway, $b) {
            Subscription::whereNotNull('gateway_token')->whereNotIn('status', ['cancelled', 'expired'])->orderBy('id')->chunk(50, function ($rows) use ($gateway, $b) {
                foreach ($rows as $s) {
                    try {
                        $data = $gateway->fetch($s->gateway_token);
                        $remote = $data['data']['response'] ?? [];
                        $status = strtolower($remote['status_text'] ?? 'unknown');
                        $pending = DB::table('subscription_changes')->where('subscription_id', $s->id)->whereIn('status', ['scheduled', 'needs_reconciliation'])->latest()->first();
                        $expected = $pending?->amount ?? $s->amount;
                        $mismatch = isset($remote['amount']) && ((int) $remote['amount'] !== Money::minor($expected));
                        if ($pending && $pending->status === 'needs_reconciliation' && ! $mismatch && isset($remote['amount'])) {
                            DB::table('subscription_changes')->where('id', $pending->id)->update(['status' => 'scheduled', 'updated_at' => now()]);
                        }$b->event($s, 'provider_reconciliation', ['status' => $status, 'amount_mismatch' => $mismatch, 'run_date' => $remote['run_date'] ?? null]);
                        if ($status === 'cancelled') {
                            $s->update(['cancel_at_period_end' => true, 'gateway_status' => 'cancelled']);
                        } elseif ($status === 'active') {
                            $s->update(['gateway_status' => 'active']);
                        }if ($mismatch) {
                            $b->notify($s, 'security_alert', 'reconcile:'.$s->id.':'.now()->format('Y-m-d'));
                        }
                    } catch (\Throwable $e) {
                        $b->event($s, 'reconciliation_failed');
                        report($e);
                    }
                }
            });
            $this->info('Provider schedules reconciled. Financial settlement requires verified payment notifications.');

            return 0;
        }) ?? 0;
    }
}
