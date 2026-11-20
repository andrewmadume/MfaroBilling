# Mfaro Billing

Organisation-owned subscription billing for Mfaro Software. A working Laravel API, React customer portal, and separate React administrator application in one repository.

**Status: development build for review, not a certified or production-ready release.** Core billing behaviour is implemented and locally tested. Live identity, PayFast sandbox acceptance, SMTP delivery, MySQL and cPanel acceptance require your external environments. Some requested capabilities are explicitly unfinished; read [readiness and gaps](docs/READINESS.md) before accepting this as a commercial launch candidate.

## What is implemented

- Five configurable products, twenty seeded plans and four bundles, with monthly/annual ZAR prices. These are editable example prices, not approved commercial prices.
- Organisation ownership, multiple memberships, switching, invitations and owner/administrator/billing-manager/member permissions.
- External OIDC authorization code + PKCE integration using an established PHP client, verified claims, encrypted server sessions, RP logout and signed backchannel logout. Keycloak realm template included. No production password authentication is implemented in Billing.
- Independent and bundled subscriptions, immutable agreement snapshots, optional trials, coupon discounts, overlap protection, period-end changes, cancellation and lifecycle commands.
- PayFast hosted recurring checkout, signature/source/server ITN checks, amount/reference/token reconciliation, transactional idempotency, provider schedule updates and cancellation. Credentials are not supplied; external operations remain unverified.
- Effective feature/limit merging across subscriptions, product/organisation-scoped API credentials, bounded SDK cache and atomic monthly transaction/lifetime storage counters.
- Invoices, PDF download, payment history, refund-recording API, basic MRR/ARR/ARPA and cash reporting, CSV payment export.
- Responsive portals, light/dark mode, searching and pagination, support tickets, audit records, encrypted settings, notification outbox and branded email.
- Customer PWA manifest/icons, public-asset-only caching, offline page, install prompt where supported, and update notification.

## Repository

```text
apps/customer-portal/    React + TypeScript + Vite customer PWA
apps/admin-dashboard/    Separate React administrator application
apps/billing-api/        Laravel 13 REST/BFF API, migrations, tests, commands
packages/ui/            Shared components, portal screens and styling
packages/shared-types/  API contracts
packages/billing-sdk/   Browser-to-product-backend integration client
examples/               Trusted PHP and Node.js entitlement integrations
infra/keycloak/         Identity realm template (no credentials)
tools/                  Local setup and production frontend build
docs/                   Architecture, API, SSO, payments, security and deployment
```

## Requirements

Use **PHP 8.4+** for the checked-in dependency lock, Composer 2, Node.js 24 LTS, and MySQL 8 for production. PHP extensions: ctype, curl, dom, fileinfo, filter, hash, intl, mbstring, openssl, pcre, PDO, pdo_mysql, session, tokenizer, xml, zip. Local tests use pdo_sqlite and SQLite. The selected Laravel framework supports PHP 8.3+, but this lock was resolved on PHP 8.4; use the documented tested runtime or regenerate and retest the dependency set on another compatible version.

## Start locally

1. Install PHP/Composer/Node using their official distributions. On Windows, run `./tools/setup-local.ps1`. Alternatively run `npm ci` at the root, then `composer install` under `apps/billing-api`.
2. Copy the backend `.env.example` to `.env`. For local work use `APP_ENV=local`, SQLite with an **absolute** database filename, `MAIL_MAILER=log`, `LOCAL_AUTH_ENABLED=true`, `SESSION_COOKIE=mfaro_local`, `SESSION_SECURE_COOKIE=false`; leave OIDC issuer and payment credentials blank. Set the public URLs to loopback ports below. Create the SQLite file.
3. From `apps/billing-api`, run `php artisan key:generate` and `php artisan migrate --seed`.
4. Create a local identity interactively: `php artisan billing:dev-user you@example.test --admin`. Choose your own password. There is no default production administrator or seeded password. Local auth refuses to run outside `APP_ENV=local`.
5. In separate terminals:

```sh
# apps/billing-api
php artisan serve --host=127.0.0.1 --port=8000
# repository root
npm run dev
npm run dev:admin
```

Customer: http://127.0.0.1:5173. Administration: http://127.0.0.1:5174. Vite proxies `/api` to port 8000. The first login has no organisation or financial records; create your organisation using the portal.

In restricted Windows environments the native esbuild dependency scanner can be denied access to ancestor directories. A verified alternative is `npm run build` followed by `npm run preview -w @mfaro/customer-portal` and `npm run preview -w @mfaro/admin-dashboard`, with the API running. Preview has the same local API proxy. Changes require rebuilding and refreshing. This limitation is specific to the sandbox used during development; production bundles do not use the scanner.

## Validate

```sh
npm run typecheck
npm test
npm run build
# apps/billing-api
php artisan test --compact
php artisan migrate --seed
php artisan billing:doctor
php artisan route:list --path=api
```

See [testing evidence](docs/TESTING.md). Do not infer that mocked gateway tests establish external payment acceptance.

## Prepare production

`npm run build:production` builds both static applications with the official API and portal URLs. Override `VITE_API_BASE_URL` and `VITE_PORTAL_URL` to change public deployment addresses. This script also sets the compiled Apache CSP API origin. Public frontend configuration must never contain secrets.

Backend configuration comes from `apps/billing-api/.env.example`. Deploy only the API `public/` directory as its web document root. Read [cPanel deployment](docs/DEPLOYMENT_CPANEL.md), [SSO](docs/SSO.md), [payments](docs/PAYMENTS.md), and [security](docs/SECURITY.md). `billing:doctor` checks prerequisites; it is not a security or compliance certification.

The source archive excludes local identities, databases, `.env`, `vendor`, `node_modules`, logs and temporary assets. Generate production frontends with `npm run build:production`; compiled `dist/` directories are excluded from Git. This repository stores the application source. Running the SaaS requires the PHP/MySQL hosting and external integrations described in the deployment guide. Nothing has been published to the Mfaro domains.
