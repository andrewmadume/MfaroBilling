# Product integration

Each Mfaro product keeps its own codebase, infrastructure and business database. Mfaro Billing supplies organisation commercial entitlements, not POS transactions, payroll records, students, bookings or invoices created by the standalone invoicing product.

## MfaroPOS first integration

1. Register an independent `mfaropos` OIDC confidential client with the real POS backend callback. Use a maintained library for Authorization Code + S256 PKCE and secure host-only sessions. Do not copy the billing cookie or expose a service credential to browser JavaScript.
2. On callback validate signatures/issuer/audience/nonce/state, then identify the user's allowed organisations from your trusted membership source. Check that any selected organisation belongs to this user on every product request. Billing service credentials do not replace end-user membership checks.
3. In Billing administration create an API client for MFAROPOS and explicitly authorise the organisation IDs it may query. Store its one-time token in the POS backend's private environment, with the billing API base `https://api.billing.mfarosoftware.africa/api/v1`. Rotate before its 90-day expiry and revoke on compromise.
4. Before serving a protected POS operation, call POST /access/check from the backend with the trusted organisation context. Verify response version/context, allowed=true, feature=true and validity. Example helpers are in examples/node/product-backend.mjs and examples/php/ProductBillingClient.php.
5. Protect sales using `sales`, stock operations using `inventory`, reports using `reports`, and branch creation using `multi_branch` plus max_branches. User/branch caps must be checked against product data transactionally before creation. Never rely only on hidden UI controls.
6. If configured, reserve transaction quota through /access/usage with a unique operation key before the product operation. Your product must coordinate quota reservations and operational writes; retries use the same key. No distributed transaction is claimed.
7. Cache privileged entitlement results in memory or private server storage for at most the returned TTL and valid_until. Key by organisation+product. Invalidate on organisation switch or your integration's subscription-change signal. Polling/rechecking provides a maximum nominal 30-second revocation window; outbound product webhooks are not implemented.
8. Return a clear paid-access-denied response to the browser and offer billing/support. Preserve authentication and appropriate product-data exports while subscribed access is suspended. Do not delete operational data on expiry.

## Frontend helper

Instantiate MfaroBillingClient with the trusted product backend's route prefix and the configured billing portal URL. Implement two authenticated product-backend routes: GET /organisation (trusted active organisation) and GET /entitlements?product=... (server-to-server billing check). The SDK methods are getCurrentOrganisation(), checkSubscription(), getProductEntitlements(), hasFeature(), getUsageLimit(), openBillingPortal() and invalidate(). openBillingPortal returns the configured URL for the host app's navigation. Its feature helpers are presentation conveniences; protected product routes independently enforce authority.

No real MfaroPOS repository or running product was provided. The examples are reusable integration modules, not a claim that an existing product has already been integrated. Use the templates below and perform a real end-to-end SSO and feature-enforcement test for each product.

| Product | Code | Representative feature checks |
|---|---|---|
| MfaroPOS | MFAROPOS | sales, inventory, multi_branch, receipts |
| MfaroInvoice | MFAROINVOICE | invoices, quotations, payment_tracking, recurring_invoices |
| Mfaro HR & Payroll | MFAROHR | employees, leave, payroll, payslips |
| MfaroEdu | MFAROEDU | students, attendance, results, parent_portal |
| MfaroBooking | MFAROBOOKING | appointments, online_bookings, reminders, reports |

## Bundle acceptance scenario

Create a verified identity, create ABC Trading, select the Mfaro Starter bundle, complete configured sandbox recurring checkout, and deliver the genuine provider ITN. Check one subscription/invoice/payment and effective access for MFAROINVOICE and MFAROBOOKING. Authenticate to each product using its own OIDC client, check the trusted organisation membership, then check its entitlement. Cancel the bundle and verify revocation at period end. If an independent active subscription overlaps, that product's access must remain. The automated backend suite exercises the billing half with simulated verified events; product-side and real-provider acceptance remains external work.
