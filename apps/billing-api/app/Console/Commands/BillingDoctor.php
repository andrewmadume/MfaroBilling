<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingDoctor extends Command
{
    protected $signature = 'billing:doctor';

    protected $description = 'Check deployment prerequisites without printing credentials.';

    public function handle(): int
    {
        $fail = [];
        if (config('billing.tax_basis_points') < 0 || config('billing.tax_basis_points') > 10000) {
            $fail[] = 'Tax basis points must be between 0 and 10000.';
        }try {
            DB::connection()->getPdo();
            $this->info('Database connection: OK');
        } catch (\Throwable $e) {
            $fail[] = 'Database connection failed.';
        }
        foreach (['storage', 'bootstrap/cache'] as $dir) {
            if (! is_writable(base_path($dir))) {
                $fail[] = $dir.' is not writable.';
            }
        }
        if (app()->isProduction()) {
            if (config('app.debug')) {
                $fail[] = 'APP_DEBUG must be false.';
            }
            if (config('billing.local_auth')) {
                $fail[] = 'LOCAL_AUTH_ENABLED must be false.';
            }
            if (config('database.default') !== 'mysql') {
                $fail[] = 'Production MySQL has not been selected.';
            }
            if (! config('session.secure')) {
                $fail[] = 'Secure cookies are required.';
            }
            if (! config('session.encrypt')) {
                $fail[] = 'Encrypted sessions are required.';
            }
            foreach (['app.url', 'billing.portal_url', 'billing.admin_url', 'billing.oidc.issuer', 'billing.oidc.redirect_uri'] as $key) {
                if (! str_starts_with((string) config($key), 'https://')) {
                    $fail[] = $key.' must be configured with HTTPS.';
                }
            }
            foreach (['billing.oidc.client_id', 'billing.oidc.client_secret', 'billing.payfast.merchant_id', 'billing.payfast.merchant_key', 'billing.payfast.passphrase'] as $key) {
                if (! config($key)) {
                    $fail[] = $key.' is missing.';
                }
            }
            if (! config('billing.payfast.enabled')) {
                $fail[] = 'PayFast is disabled.';
            }
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $fail[] = 'A real email transport must be configured.';
            }
        }
        foreach ($fail as $message) {
            $this->error($message);
        }
        $this->line('This check does not certify security, regulatory compliance, DNS, cron execution or live gateway/SSO behaviour.');

        return count($fail) ? 1 : 0;
    }
}
