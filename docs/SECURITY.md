# Security model and review

This application contains technical controls, not a claim of POPIA, PCI DSS or other regulatory certification. Legal documents, retention periods, merchant obligations, tax settings and product export procedures require business/professional review before launch.

Implemented controls: verified OIDC claims, code + S256 PKCE, state/nonce from the client library, encrypted HTTP-only sessions, secure production cookies/HTTPS, CSRF protection, exact-origin credentialed CORS, role/membership checks, organisation query scoping, expiring hashed service credentials, provider signature/source/server validation, payment idempotency, private invoice generation, input validation, ORM parameter binding, framework escaping, audit events, secret-free frontend settings and API no-store headers.

Do not cache API responses, ID/access/refresh tokens, session cookies, invoices or other billing data in a service worker, CDN, shared proxy or local storage. The PWA caches public icons/offline content and hashed JS/CSS assets only. Navigations fetch from the network and use an informational offline page on failure. Product entitlement caches are privileged, per organisation/product and capped at 30 seconds/valid_until. Server operations fail closed once cached authority expires.

Laravel APIs return no-store. Production portal .htaccess supplies CSP, frame denial, nosniff, referrer and permissions headers. Configure HSTS on frontend virtual hosts after TLS is correct, and review Apache/API headers and allowed origins in the target environment. Avoid wildcard trusted proxies; this affects webhook source verification. Review PHP display_errors/logging and disable debug pages in production.

The IdP owns password hashing/reset/verification/MFA; Billing must not implement a parallel password recovery system. Local test passwords use Laravel hashing and are gated by environment plus an explicit flag. They are never seeded in production. Billing admins are promoted from a trusted server console. Enforce MFA and restricted IdP management access before granting a platform admin role.

Data requests: customers can export their account/membership data, correct organisation details, manage identity information at the IdP and record a deletion request. An operator must review deletion/anonymisation across the IdP, billing and individual products; financial history is not automatically deleted. Product operational data export remains the independent product's responsibility and must be available appropriately when paid access expires. Consent/legal acceptance records and automated retention enforcement are not complete in this build.

Important residual review items: no independent penetration test, no tamper-evident audit sink, no confirmed production WAF/rate-limit tuning, no verified backup restore on the hosting account, no real IdP/payment acceptance, no MySQL concurrency/load run, and no complete immutable double-entry/credit-note accounting ledger. Financial changes involving uncertainty require human reconciliation. Do not treat a request validation error or an active provider schedule as proof that a payment was never collected.

Before production review the full dependency audit, APP_KEY backup, private filesystem boundaries, CORS, cookie settings, webhook/proxy configuration, administrative MFA, code-change permissions, email injection/escaping, tenant IDOR attempts, denial/service outages, recovery paths and operational alerts. Keep dependencies patched using their lockfiles and rerun the billing/security suite after updates.

Report suspected issues privately to the platform operator. Do not include live secrets or customer card information in support tickets, screenshots or public issues.
