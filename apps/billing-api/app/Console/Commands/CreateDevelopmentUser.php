<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateDevelopmentUser extends Command
{
    protected $signature = 'billing:dev-user {email} {--admin}';

    protected $description = 'Create a local-only account with an interactive password; never enabled in production.';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('billing.local_auth')) {
            $this->error('Local authentication must be explicitly enabled in the local environment.');

            return 1;
        }$password = $this->secret('Choose a password (at least 14 characters)');
        if (strlen($password ?? '') < 14) {
            $this->error('Password is too short.');

            return 1;
        }$u = User::firstOrCreate(['email' => $this->argument('email')], ['name' => 'Development account', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        if ($this->option('admin')) {
            $u->is_admin = true;
            $u->save();
        }$this->info('Local development account created. No organisation or financial data was seeded.');

        return 0;
    }
}
