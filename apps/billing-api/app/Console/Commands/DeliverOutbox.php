<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverOutbox extends Command
{
    protected $signature = 'billing:deliver';

    protected $description = 'Deliver queued branded notifications with bounded retries.';

    public function handle(): int
    {
        return Cache::lock('billing-outbox', 55)->get(function () {
            $rows = DB::table('outbox_events')->whereNull('delivered_at')->where('attempts', '<', 5)->orderBy('created_at')->limit(30)->get();
            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($row) {
                        $e = DB::table('outbox_events')->where('id', $row->id)->lockForUpdate()->first();
                        if ($e->delivered_at) {
                            return;
                        }$payload = json_decode($e->payload, true);
                        $template = config('notification_templates.'.$e->type);
                        $subject = $template[0] ?? ucwords(str_replace('_', ' ', $e->type));
                        $body = $template[1] ?? 'There is an update to your Mfaro Billing account. Review the portal for details.';
                        Mail::send('emails.notification', ['subject' => $subject, 'payload' => $payload, 'body' => $body], fn ($m) => $m->to($e->recipient)->subject('Mfaro Billing · '.$subject));
                        DB::table('outbox_events')->where('id', $e->id)->update(['delivered_at' => now(), 'attempts' => $e->attempts + 1, 'last_error' => null, 'updated_at' => now()]);
                    });
                } catch (\Throwable $e) {
                    DB::table('outbox_events')->where('id', $row->id)->update(['attempts' => $row->attempts + 1, 'last_error' => 'Delivery failed; inspect private mail logs.', 'updated_at' => now()]);
                    report($e);
                }
            }
            $this->info('Outbox delivery completed.');

            return 0;
        }) ?? 0;
    }
}
