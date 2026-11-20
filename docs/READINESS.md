# Readiness and remaining work

**This is a working development application, not completion of every requirement in the original commercial-platform brief. It must not be deployed as an approved live billing service yet.**

## External configuration and acceptance gates

| Gate | Status |
|---|---|
| React portals, real API, migrations and seed catalogue | Implemented; locally exercised |
| Billing/tenant/security tests and SDK tests | Passing locally; see TESTING.md |
| Production MySQL migration, isolation and concurrency | Not run; no server/credentials supplied |
| cPanel/DNS/TLS/storage/cron/SMTP/restore | Runbook prepared; no hosting access supplied |
| External identity, registration, verification, MFA and multi-product SSO | Code/config boundary implemented; no provider configured |
| PayFast sandbox checkout and recurring acceptance | Adapter/tests provided; no merchant configured |
| Existing Mfaro product integration | SDK and backend templates provided; no actual product repositories supplied |
| Legal policies, taxation, retention and mandatory admin MFA | Require business/provider configuration and review |

## Functional gaps beyond external credentials

These remain engineering work, not merely an environment switch:

- Immediate prorated plan upgrades, paid trials, pause/resume, sophisticated migration of multiple independent mandates into a bundle and self-service outstanding-payment recovery without duplicating mandates. Implemented changes are period-end only; terminated mandates require a new agreement. Operator support is the current recovery path.
- Automatic provider refunds/chargebacks and a complete credit-note/refund-tax/reconciliation ledger. Refunds can be recorded after an externally completed transaction, with bounds checking; the application does not execute or fully account for them.
- The complete requested analytics suite (cohort churn/retention, recovery rate, conversion/time-based revenue allocation, every filter, XLSX and PDF report export). Current reporting is basic MRR/ARR/ARPA and six-month gross cash with CSV export. Annual recurring values are normalised; full accounting revenue recognition is not implemented.
- Full platform-administrator role management UI, owner-transfer workflow, support-ticket reply/resolution tools and exhaustive CRUD/filtering for every proposed entity. Existing admin screens are implemented only for the operations they expose, with no inert buttons.
- Full company/SMTP/gateway/tax/branding/template configuration editors. Security-sensitive integration settings currently use private environment variables; a smaller encrypted application-settings form is available.
- A complete event-specific email template editor, all requested notification variations/preferences, and transactional notification history available to every intended role. The outbox and branded reusable HTML template work locally, but no production SMTP delivery has been tested.
- Legally reviewed privacy/terms/subscription/cancellation documents, consent/acceptance ledger, automated retention/anonymisation and complete organisation financial-data export/deletion review tooling. Product-data exports belong to each independent product and require implementation there.
- A full browser automation regression suite, physical-device PWA acceptance, tamper-evident audit storage, operational alerting and load/penetration testing. API and SDK tests are checked in; the browser observations are recorded separately.

## Commercial invariants to preserve

Never grant paid access from a browser redirect; never trust a submitted organisation ID without membership or service scope; never initiate local renewals in addition to provider-managed charges; preserve historical agreement snapshots; process payment events idempotently; combine entitlement sources without accidental revocation; preserve financial records; keep secrets outside public roots and frontend bundles.

Use this file as the review handoff. A clean build and passing unit suite are necessary but insufficient for launch. Advance each remaining requirement with implementation and acceptance evidence before marking the original brief complete.
