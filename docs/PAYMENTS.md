# PayFast integration

Official documentation was consulted during implementation on 20 November 2026. This adapter uses documented custom recurring checkout, ITN validation and subscription-maintenance endpoints. It has not been accepted against a configured merchant sandbox or live account in this run. Do not enable live payments based solely on local tests.

## Configuration and checkout

Create your own PayFast merchant sandbox, enable recurring payments, and set its required passphrase. Set PAYFAST_ENABLED=true, PAYFAST_SANDBOX=true and the sandbox merchant ID/key/passphrase in the private backend environment. Never mix live and sandbox credentials. Leave the gateway disabled when credentials are absent.

The backend signs ordered hosted checkout fields with the merchant passphrase. The subscription uses a stable merchant reference, ZAR amount, subscription_type=1, monthly/annual frequency, a future billing date, recurring_amount and indefinite cycles. These are provider-managed schedules. The initial invoice's tax-inclusive total is also the recurring amount. Only supported recurring card authorisations are used. No arbitrary local retry job submits another charge.

Sandbox form destination is `https://sandbox.payfast.co.za/eng/process`; live is `https://www.payfast.co.za/eng/process`. Confirmation URL is `https://api.billing.mfarosoftware.africa/api/v1/webhooks/payfast`. Return/cancel pages are informational, and never activate a subscription.

## Notification validation

The adapter validates field shape and merchant ID, verifies the documented signature with constant-time comparison, compares the actual request source IP against DNS-resolved PayFast hosts, and posts the received parameter string to the provider's `eng/query/validate` endpoint. Referer headers are not trusted as proof of origin. TLS validation is enabled. If behind a proxy, trust only your actual proxy and verify that Laravel receives the real PayFast source IP; do not simply disable the source check.

The processor then locks the subscription and reconciles merchant reference, ZAR amount, recurring token, notification key and provider payment ID. Successful first payment requires a recurring authorisation token. A verified completed payment records one payment, pays one invoice, changes state and grants access transactionally. Subsequent unique provider payments extend one period. Repeated ITNs are acknowledged without duplicating payments, invoices or periods. Late payments for terminated agreements are retained as needs_review and do not silently restore access.

## Renewal, failure and changes

PayFast owns collection attempts and retries. Local cron handles reminders, grace expiry, suspension and schedule comparison without charging cards. Failed verified notifications create failed attempts and a bounded grace period; paid recovery reactivates eligible agreements. Paid access ends after the configured policy while billing, support and product export pathways remain available.

API maintenance uses `https://api.payfast.co.za/subscriptions/{token}/{action}` with its documented merchant/version/timestamp and sorted header/body signature. Sandbox maintenance adds testing=true. Fetch reads schedules, PATCH update changes the amount in integer cents/frequency/run date, and PUT cancel ends the provider schedule. Card updates use the provider-hosted recurring-update URL. Keys and passphrases never enter browser code.

Plan/bundle/interval changes are period-end only and require at least 24 hours before renewal. The customer explicitly reviews the new recurring amount. Current coverage remains unchanged until the expected renewal is verified. Ambiguous updates are durable needs_reconciliation intents. Hourly fetch confirms target amounts before allowing them to be treated as scheduled; unsupported differences require an operator to investigate. Immediate prorated upgrades, pause/unpause, ad-hoc collections and automated refund/chargeback workflows are not implemented.

A pending-checkout guard prevents accidental repeat checkout authorisations. Financial correctness still depends on provider behaviour, correctly configured callbacks, stable gateway references and operational reconciliation. Do not re-submit a browser checkout form after an uncertain outcome; inspect PayFast first. There is no claim that a merchant-signed form is cryptographically single-use at the external provider.

## Required sandbox acceptance

1. Publish a secure staging API that PayFast can reach; loopback cannot receive external ITNs.
2. Use your own sandbox credentials and whitelist the staging server's outbound IP if enabled.
3. Buy an independent monthly product and an annual bundle; verify actual gross amounts and frequencies in both systems.
4. Confirm valid ITN processing, duplicate delivery, altered signatures, wrong amounts and wrong tokens.
5. Exercise a real sandbox renewal, failed-payment sequence, cancellation, card update and period-end change.
6. Interrupt a provider update and reconcile the uncertain outcome before retrying.
7. Compare provider transactions to payments/invoices; resolve missing notifications under a reviewed manual procedure. Fetching an active schedule alone is not settlement proof.
8. Verify cancelled/refunded transactions and access separately. Record externally completed refunds via the admin API; it does not initiate refunds.
9. Switch to live credentials only after merchant approval and staging acceptance. Disable the gateway during a rollback/uncertain cutover to prevent checkout creation.

Sources: [official integration documentation](https://developers.payfast.co.za/docs), [official API](https://developers.payfast.co.za/api), [official PHP SDK](https://github.com/PayFast/payfast-php-sdk). Recheck the provider documentation before live activation, since its requirements can change.
