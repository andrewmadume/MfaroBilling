<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BillingTick extends Command
{
    protected $signature = 'billing:tick';

    protected $description = 'Process subscription expiry, dunning and renewal reminders; never charge cards.';

    public function handle(BillingService $b): int
    {
        return Cache::lock('billing-tick', 55)->get(function () use ($b) {
            Subscription::whereNotIn('status', ['cancelled', 'expired'])->orderBy('id')->chunk(100, function ($rows) use ($b) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $b) {
                        $s = Subscription::whereKey($row->id)->lockForUpdate()->firstOrFail();
                        if ($s->cancel_at_period_end && (! $s->period_end || $s->period_end->isPast())) {
                            $b->transition($s, 'cancelled');

                            return;
                        }
                        if ($s->status === 'trialing' && $s->trial_ends_at?->isPast()) {
                            $b->transition($s, 'expired');
                            $b->notify($s, 'trial_ended', 'trial-ended:'.$s->id);

                            return;
                        }
                        if ($s->status === 'pending' && $s->created_at->lt(now()->subDays(7))) {
                            $b->transition($s, 'expired');

                            return;
                        }
                        if ($s->status === 'active' && $s->period_end?->isPast()) {
                            $b->transition($s, 'past_due');
                            $s->update(['grace_ends_at' => $s->period_end->addDays(config('billing.grace_days'))]);
                            $b->notify($s, 'payment_failed', 'overdue:'.$s->id.':'.$s->period_end->format('Y-m-d'));
                        }
                        if (in_array($s->status, ['past_due', 'grace_period'], true)) {
                            if ($s->grace_ends_at?->isPast()) {
                                $b->transition($s, 'suspended');
                                $b->notify($s, 'subscription_suspended', 'suspended:'.$s->id.':'.$s->grace_ends_at->format('Y-m-d'));
                            } else {
                                if ($s->status === 'past_due') {
                                    $b->transition($s, 'grace_period');
                                }$b->notify($s, 'grace_period_warning', 'dunning:'.$s->id.':'.now()->format('Y-m-d'));
                            }
                        }
                        if (in_array($s->status, ['active', 'trialing'], true) && $s->period_end?->between(now(), now()->addDays(7))) {
                            $b->notify($s, $s->status === 'trialing' ? 'trial_ending' : 'upcoming_renewal', 'renewal:'.$s->id.':'.$s->period_end->format('Y-m-d'), ['renewal_date' => $s->period_end->format('d M Y')]);
                        }
                    });
                }
            });
            DB::table('sessions')->where('last_activity', '<', now()->subMinutes(config('session.lifetime'))->timestamp)->delete();
            DB::table('user_sessions')->whereNotIn('session_id', DB::table('sessions')->select('id'))->delete();
            $this->info('Lifecycle processing complete. No gateway charges initiated.');

            return 0;
        }) ?? 0;
    }
}
