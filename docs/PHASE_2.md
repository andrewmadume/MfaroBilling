# Phase 2 — billing lifecycle

Scope agreed for this phase: payment recovery, pause/resume and refunds. These changes build on the existing organisation-owned subscriptions and provider-managed recurring schedules. This phase does not make the entire original platform brief production-ready.

## Customer behaviour

- Active paid subscriptions can pause 1–12 provider collection cycles. Current paid access remains until period_end. At that boundary, voluntary pauses suspend access without adding a payment-failure grace period.
- Resume restores provider collections. Existing unexpired paid access can resume; expired access remains suspended until an authenticated successful payment is processed. No free extension or ad-hoc collection is created.
- Past-due/grace/suspended subscriptions can inspect provider status and follow the hosted card-update link. PayFast owns retries. Locked, cancelled or otherwise restricted mandates need merchant assistance; Billing never creates a duplicate recovery checkout.
- Provider cancellation now persists an intent and idempotency key before the external call. A timeout or interrupted response cannot make another request silently repeat it. Trial/pending cancellation without a provider token remains local and does not need a merchant connection.
- Credit notes list refund amount, subtotal and tax, link to the original invoice and support authorised PDF downloads. Refunds do not automatically cancel subscriptions or remove other entitlement sources.

## Refund workflow

The administrator chooses a payment and amount/reason, requests an eligibility quote, and confirms its exact hash. The backend re-queries the original payment's refundable balance, requires original-source eligibility, checks local unrefunded amounts and reserves a durable operation. Only a documented successful provider response produces the local refund and credit note automatically.

Set PAYFAST_REFUNDS_ENABLED=true only after configuring and acceptance-testing merchant refund capability. It defaults to false. PAYFAST_ENABLED and merchant credentials are independently required. Sandbox support/merchant eligibility must be verified with PayFast; mocked tests do not establish that a real sandbox will accept refunds. Bank payout details are neither collected nor stored by this implementation; complete unsupported refunds through the merchant dashboard and use the external-record workflow.

Amounts use integer minor units. Credit-note tax allocation is cumulative against the original invoice's tax/total with arbitrary-precision integer multiplication; partial refunds do not accumulate rounding drift, and a full refund credits exactly the original tax. Financial records are retained. External refund references are idempotent and cannot be reused for another amount/payment.

## Durable operations and uncertainty

gateway_operations stores organisation, resource, requester, kind, key, request hash, status and sanitised result. Unresolved operations block competing mutations for the same subscription/payment. Reusing a key with another payload returns 409. Successful replays return the original operation without contacting the provider. Unconfigured gateways fail before recording an uncertain operation.

The scheduler's billing:reconcile-operations runs every five minutes with a concurrency lock. It never repeats a pause, resume, cancel or refund request. Schedule reconciliation fetches current state. A stale processing operation becomes uncertain if it cannot be confirmed. A refund with a persisted successful acknowledgement can finish its local ledger transaction after an interruption. A refund without that proof needs human reconciliation; provider balance differences alone are not attributed to a particular intent.

Gateway operations in administration offers read-only reconciliation and an explicit operator resolution workflow. Before asserting processed/not processed, inspect merchant records and confirm that nothing remains pending. Supply the merchant/support evidence reference and attest to the finding. The determination is audited; confirmed_processed refunds create their credit note exactly once. confirmed_not_processed releases the failed intent but never automatically sends another financial action. This deliberately trusts an authorised operator's reviewed determination, rather than treating a missing HTTP response as evidence.

## Upgrade and verification

1. Back up the database and private environment. Deploy the new source and run php artisan migrate --force.
2. Run php artisan billing:backfill-credit-notes to create missing notes for previously recorded refunds. It is repeatable and sends no gateway requests. Review unsupported legacy invoice allocations manually.
3. Deploy both rebuilt frontends and retain the existing cron entry. The new command is registered in the scheduler automatically.
4. Keep refund execution disabled until merchant acceptance. Test pause/unpause, card recovery, actual refunds, network interruption, delayed notifications and reconciliation in an isolated merchant/staging environment.
5. Monitor needs_reconciliation operations, private logs and operator evidence. Application-level locks/idempotency are covered locally; MySQL multi-process contention and target-host acceptance remain external checks.

Local validation: targeted lifecycle/refund tests, the full backend regression suite, SDK tests, TypeScript checks and both React builds. Browser checks use a labelled simulated payment and disabled gateway; no actual provider transaction is performed. Final measured counts are appended after the run.

Authoritative provider references: [PayFast API](https://developers.payfast.co.za/api) and [PayFast integration guide](https://developers.payfast.co.za/docs). Live merchant behaviour remains unverified.

## Final local results

- Backend: 90 tests passed, 312 assertions (28 tests added for Phase 2).
- SDK: 4 tests passed. TypeScript checking and both production frontend builds passed.
- Migrations executed on SQLite; legacy credit-note backfill ran and was repeatable.
- Browser: an explicitly simulated external refund produced a customer-visible credit note; the pause dialog handled a disabled provider without creating an uncertain operation. No real provider was contacted or refunded.
- Live PayFast, MySQL concurrency and target cPanel acceptance remain unverified.
