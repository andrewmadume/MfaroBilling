<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoteAdministrator extends Command
{
    protected $signature = 'billing:admin {email} {--revoke}';

    protected $description = 'Promote or revoke an existing verified identity from a trusted server console.';

    public function handle(): int
    {
        $u = User::where('email', $this->argument('email'))->whereNotNull('email_verified_at')->firstOrFail();
        $u->is_admin = ! $this->option('revoke');
        $u->save();
        DB::table('audit_logs')->insert(['id' => Str::uuid(), 'user_id' => $u->id, 'action' => $u->is_admin ? 'admin.promoted' : 'admin.revoked', 'payload' => '{"origin":"server_console"}', 'created_at' => now()]);
        $this->info('Platform role updated and audited.');

        return 0;
    }
}
