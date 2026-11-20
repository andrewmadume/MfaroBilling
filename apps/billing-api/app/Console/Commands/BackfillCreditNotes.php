<?php

namespace App\Console\Commands;

use App\Payments\RefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCreditNotes extends Command
{
    protected $signature = 'billing:backfill-credit-notes';

    protected $description = 'Create missing credit notes for already recorded external refunds; never contact a gateway.';

    public function handle(RefundService $refunds): int
    {
        $count = 0;
        DB::table('payment_refunds')->orderBy('created_at')->orderBy('id')->chunk(100, function ($rows) use ($refunds, &$count) {
            foreach ($rows as $r) {
                if (! DB::table('credit_notes')->where('payment_refund_id', $r->id)->exists()) {
                    DB::transaction(fn () => $refunds->credit($r->id));
                    $count++;
                }
            }
        });
        $this->info('Created '.$count.' credit notes. No payments were sent.');

        return 0;
    }
}
