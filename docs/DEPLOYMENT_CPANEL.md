# cPanel deployment runbook

The code is prepared for cPanel; no hosting account, DNS zone, certificate or merchant account was accessed or changed. Verify your provider's PHP/extension/MySQL/process/network capabilities before uploading.

## Prerequisites

- PHP 8.4+ CLI and Apache handler using the same compatible runtime; Composer 2, required extensions listed in README, outbound HTTPS/cURL, MySQL 8, and cron access. SQLite is for local testing only.
- Check `php -v`, `php -m`, `composer check-platform-reqs`, DB server version, InnoDB, utf8mb4 and timezone UTC. cPanel may expose `/opt/cpanel/ea-php84/root/usr/bin/php`; confirm the actual path.
- Separate external Keycloak/managed OIDC service. Shared hosting is not assumed to support Java, persistent workers, Redis, containers or WebSockets.
- Working SMTP, TLS, private backup storage and enough time/memory for bounded cron jobs.

## DNS and document roots

| Hostname | Destination/root |
|---|---|
| mfarosoftware.africa | Existing company site; no change required |
| billing.mfarosoftware.africa | Customer portal static `dist/` |
| admin.billing.mfarosoftware.africa | Admin static `dist/` |
| api.billing.mfarosoftware.africa | Private release's `apps/billing-api/public/` |
| accounts.mfarosoftware.africa | External identity host/reverse proxy |

Create each required subdomain in cPanel, then add the hosting provider's A/AAAA or CNAME records to your authoritative DNS zone. `accounts` must point to the identity infrastructure, not a PHP document root. Nested admin.billing needs its own DNS record and certificate; a certificate for *.mfarosoftware.africa does not cover it. Provision valid certificates for every hostname and redirect HTTP at Apache/hosting level. The API also refuses insecure production requests.

## Build and upload

Build on a trusted development/CI host:

```sh
npm ci
npm run typecheck
npm test
npm run build:production
```

The production build script defaults to the official public URLs; use VITE_API_BASE_URL and VITE_PORTAL_URL to override them. It updates the compiled CSP connect-src origin. Upload the contents, including `.htaccess`, of customer/admin `dist/` into their respective roots. These are separate apps. Apache rewrite sends unknown SPA routes to index.html. Ensure mod_rewrite and AllowOverride permit the supplied rules. No PHP secrets are included in frontend builds.

Keep the backend and vendor/private storage outside public_html, for example `/home/CPANEL_USER/apps/mfaro-billing/releases/RELEASE/apps/billing-api`. Only its public directory is exposed. Install its locked dependencies:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs
```

If shell/Composer is unavailable on cPanel, prepare vendor on a matching PHP 8.4 build host and upload it privately. Confirm platform requirements on the target. Do not upload a dev database, `.env` from development, tests as public content, node_modules, local logs or a development PHP server.

## Database and environment

Create the `mfaro_billing` database and a dedicated DB user via cPanel's MySQL wizard; use the actual prefixed database/user names. Initially grant only privileges required to migrate. Remove schema-changing privileges from the runtime account after migration, using a separate migration account for future releases where hosting supports it. Do not use root/shared credentials.

Copy the backend `.env.example` to private `.env`. Set APP_ENV=production, APP_DEBUG=false, APP_URL and portal/admin URLs, DB credentials, SMTP, identity configuration and your PayFast sandbox configuration. Set LOCAL_AUTH_ENABLED=false, encrypted DB sessions, secure host-only __Host cookie, exact CORS origins and production timezone UTC. Do not set SESSION_DOMAIN to a broad parent domain. Keep .env readable only by the account/application process (typically 600). Generate APP_KEY once using `php artisan key:generate`; retain it across releases and back it up securely.

Configure company/legal address, VAT number and TAX_BASIS_POINTS only after business review. Default tax is zero; no VAT registration is presumed. Set SUBSCRIPTION_GRACE_DAYS to your approved commercial policy. SMTP from-address must be authorised; publish the mail provider's SPF/DKIM/DMARC records and test delivery.

```sh
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
php artisan billing:doctor
```

Seeded catalogue prices are examples. Review and edit them before taking payments. Register a verified identity through the provider, then promote that exact existing account from the trusted console: `php artisan billing:admin owner@your-company.example`. The --revoke option removes the role. There is no production default password. Configure mandatory administrator MFA at the identity provider.

Storage and bootstrap/cache must be writable by the PHP account. Use ownership-correct 750 directories and 640 files as a starting point; grant write only where required, never recursive 777. Keep financial documents/private storage outside the document root. No public storage link is required for invoices.

## If the API document root cannot be changed

Ask the provider to set the API subdomain to Laravel public/. If unavailable, keep the complete backend under the private application path and copy **only public directory files** to the API web root. Edit the copied index.php's two bootstrap/autoload paths to the private absolute paths. Use a private constant path, e.g. `$backend = '/home/CPANEL_USER/apps/mfaro-billing/current/apps/billing-api';` then require `$backend.'/vendor/autoload.php'` and `$backend.'/bootstrap/app.php'`. Keep the normal maintenance-file path pointed to private storage as well. Include the public .htaccess. Never move `.env`, app/, database/, vendor/ or storage/ into public_html, and never rely on dotfile denial as the only protection.

## Cron and queues

Create one cPanel cron entry per production deployment (replace paths):

```cron
* * * * * cd /home/CPANEL_USER/apps/mfaro-billing/current/apps/billing-api && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /home/CPANEL_USER/logs/mfaro-scheduler.log 2>&1
```

The scheduler runs lifecycle/reminders, notification delivery, hourly provider reconciliation and a short `queue:work --stop-when-empty` invocation. Database-backed distributed locks and withoutOverlapping protect concurrency. Do not run another schedule on a staging installation pointed to the production DB. Shared hosts can kill long tasks; verify execution duration and cron limits. Monitor failed_jobs and undelivered outbox rows. On a VPS, use supervised workers and one scheduler instead.

## Acceptance and operations

Check `/up`, application assets and SPA deep links, secure cookie attributes, allowed/disallowed CORS, OIDC callbacks/logouts/MFA, product client scopes, cross-tenant denials, real sandbox ITNs, renewals, cancellations, plan changes, PDFs, SMTP and cron. Follow TESTING.md and READINESS.md. The normal frontend build success does not establish live configuration correctness.

Laravel logs are private under storage/logs. Rotate logs and cron output, restrict access and alert on repeated ITN rejection, reconciliation failures, needs_review payments, pending changes, queue/outbox failures and overdue renewals. Do not record full webhook secrets or card data in logs. `/up` is a basic liveness route, not proof that the whole billing system is healthy.

## Backup and restore

Create encrypted offsite backups of the MySQL database, private files and release configuration. Keep APP_KEY and environment secrets in a separate encrypted secret store. Use cPanel Backup or a least-privilege backup account and mysqldump with a private mode-600 defaults file; do not put passwords in command-line arguments or public scripts. Configure retention with the business's financial/privacy policy. Perform a restore drill into an isolated staging database, disable payments/email/cron there, migrate if needed, and compare counts and invoice/payment totals before launch.

## Rollback

Before deployment record the release/version and DB backup. Use maintenance mode for incompatible migrations. Keep prior code and static bundles. Roll back code via a private current symlink or provider-controlled document-root switch; restore frontend assets from the matching build. Do not run destructive down migrations against financial history. Restore the pre-release DB only under an approved recovery procedure that reconciles payments received since backup; otherwise apply a forward repair. Stop checkout and scheduler during uncertain cutover, reconcile PayFast after recovery and resume only once duplicate-charge/notification risks are understood. Preserve the original APP_KEY.
