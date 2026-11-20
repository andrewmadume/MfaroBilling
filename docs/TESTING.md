# Verification evidence

Development verification date: 20 November 2026.

The backend suite currently contains 62 passing tests/160 assertions, plus 4 passing integration-SDK tests. See the checked-in test files for exact coverage and rerun after changes. Local migrations and seeders executed successfully on SQLite. Both React applications passed TypeScript checking and production compilation. PHP and JavaScript dependency audits reported no known vulnerabilities in the installed set at the time checked.

## Automated coverage

Money parsing/rounding, discounts/tax, renewal date overflow, state transitions, organisation ownership, membership boundaries, cross-tenant record IDs, administrator gates, role escalation denial, idempotency key conflicts, catalogue snapshots, overlapping coverage, bundle activation, duplicate ITN processing, recurring renewal, failed-payment grace and suspension, trial eligibility/expiry, secure PDF authorisation, checkout configuration/signature shape, invalid ITN signatures, product/organisation token scopes and expiry, refund amount bounds, forged status denial, rate limiting, encrypted-session integration boundaries, period-end provider updates, uncertain update reconciliation, checkout replay guard, sensitive-token redaction and quota consumption.

SDK tests cover TTL reuse, cache invalidation, organisation switch, product/context mismatch and entitlement outage. Payment event processor tests call the verified-event boundary; they deliberately do not pretend to contact PayFast. Http::fake tests exercise documented maintenance request shape and local intent semantics.

## Browser observations

Real local Laravel HTTP API and built React frontends were exercised through the in-app browser. The development login completed, an organisation was created, a bundle trial produced one subscription and two accessible application cards, administration reflected the stored trial, and a new product created through the admin form persisted in the catalogue. Mobile sidebar and dark mode were checked, and no console warnings/errors were observed in the tested final flows. Screenshots are under docs/screenshots/. They show explicitly named local QA records; those identities and databases are excluded from the distributable archive.

The restricted Windows sandbox blocked esbuild's native dependency scanner. Browser acceptance used Vite's successful compiled-build preview with the same API proxy. Native config loading is specified in scripts to avoid the config bundler's directory traversal. This does not establish that hot module replacement works in this sandbox.

## Not verified

- Actual hosted OIDC registration/verification/reset/MFA, multiple independent product clients, real signed backchannel logout and central logout.
- Actual PayFast sandbox/live checkout, provider-source IPs through the target proxy, server confirmation, renewals, schedule updates, failed-payment retries, card updates and cancellations.
- MySQL 8 migrations/concurrency, the customer's cPanel PHP/Apache configuration, DNS/TLS, cron, production SMTP and backup restoration.
- PWA installation/update behaviour on physical Android/iOS/desktop devices and real offline transitions.
- Independent penetration testing, load testing, accessibility audit, financial reconciliation against a real merchant ledger and legal/compliance review.

Do not describe those scenarios as passed based on unit tests or screenshots. Before live release run them in a separate staging tenant/merchant environment, resolve every gap in READINESS.md, and retain timestamped acceptance evidence.
