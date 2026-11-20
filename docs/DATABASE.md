# Database

Production target: MySQL 8, InnoDB, utf8mb4, database `mfaro_billing` (cPanel may prefix the account name). The Laravel migrations are the authoritative schema. Local migration execution and automated tests used SQLite; production MySQL execution remains an acceptance task.

## Implemented tables

| Area | Tables |
|---|---|
| Identity and tenancy | users, organisations, organisation_members, organisation_invitations, identity_provider_links, user_sessions, sessions |
| Catalogue and agreements | products, plans, bundles, bundle_items, offers, offer_prices, price_versions, coupons, subscriptions, subscription_items, subscription_events, subscription_changes |
| Financial records | invoices, invoice_items, payment_attempts, payments, payment_refunds, gateway_webhook_events |
| Access | entitlement_grants, usage_records, api_clients, api_client_credentials, product_organisation_access |
| Operations | idempotency_keys, outbox_events, notifications, audit_logs, system_settings, support_tickets, jobs, job_batches, failed_jobs, cache, cache_locks, password_reset_tokens, migrations |

The original brief names additional tables for future capabilities. They have not been added as empty placeholders. A separate refund credit-note ledger, per-plan/multi-rate taxation, independent payment-method vault and richer scheduling/history models are still gaps; see READINESS.md.

Business records use UUID primary keys, identity users use Laravel's numeric key, and tenant and subscription FKs are indexed. Financial ownership FKs restrict deletion. Nullable unique plan/bundle associations give one offer per plan or bundle. Payment provider references, webhook keys, organisation idempotency keys and invitation token hashes are unique.

Amounts are DECIMAL(14,2), currencies are explicit three-character codes, calculations use integer minor units, and tax basis points use integer rounding. UTC is used for application timestamps. Provider billing dates are interpreted in the merchant timezone. Do not replace money calculations with JavaScript or PHP binary floating-point arithmetic for ledger writes.

`price_versions` preserves catalogue changes. `subscriptions.snapshot` preserves the binding agreement; editing a plan/bundle cannot rewrite it. Subscription and audit events are append-only through the application's code. Database administrators can still alter rows; tamper-evident external log storage is a future hardening item.

Invitations store SHA-256 token hashes; service tokens are hashed and returned once. Session payloads are encrypted using APP_KEY. Settings values use Laravel encryption. The APP_KEY is necessary to restore encrypted data and must be backed up separately from the database.

`usage_records` supports monthly transaction counters and append-only lifetime storage-byte consumption. User and branch limits are returned for each product to enforce against its own operational records. Cross-system creation/usage needs the product to reserve usage first and implement its own idempotent write/compensation strategy.

Migrations that introduce financial history intentionally reject destructive down operations. Use forward migrations and restore a tested pre-release backup for rollback. Never run migrate:fresh on a production database. Seeders use firstOrCreate and do not overwrite administrator edits or create production accounts.
