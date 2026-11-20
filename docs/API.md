# API v1

Production base: `https://api.billing.mfarosoftware.africa/api/v1`. Exact implemented routes and machine-readable request/response contracts are in `openapi.json`. No endpoint should be inferred from the initial brief if it is absent from the route list.

## Browser session

GET `/auth/session` establishes the API session and returns a CSRF value. For state-changing browser requests send `X-CSRF-TOKEN`, `Content-Type: application/json`, `Accept: application/json` and credentials. The CSRF value is not an authentication bearer token. Both frontends use exact CORS origin allowlists. Production requires HTTPS. Session cookies stay on the API host.

GET `/auth/oidc` starts central sign-in; `/auth/callback` finishes it. Customer registration, verification, forgotten passwords, resetting passwords, MFA and profile editing take place at the selected identity provider rather than a second local password database. POST `/auth/login` is a strictly local-only test convenience and returns 404 outside local environments.

## Tenant context and roles

Get `/organisations`, select one of the caller's memberships, and send its ID as `X-Organisation-ID`. Scoping middleware rejects other organisations with 403. A record ID belonging to a different tenant returns 404 after membership has been checked.

| Role | Capabilities |
|---|---|
| owner | Organisation/team, roles, invitations, subscriptions and finances, purchased apps |
| administrator | Organisation/team except changing owner/admin role hierarchy, subscriptions and finances, apps |
| billing_manager | Subscriptions, payments and invoices; apps |
| member | Apps, non-financial workspace overview, team list, support |

Membership does not imply product access. Product backends must perform both membership and entitlement checks. Owners cannot be removed or silently demoted; ownership transfer is not implemented.

## Commercial commands

POST `/subscriptions` accepts an `offer_price_id`, optional coupon and explicit overlap acceptance. `Idempotency-Key` is required and must be unique for a new intent. Retrying the same body/key returns the original subscription. Reusing a key for a different body returns 409. Status begins pending, and one open invoice is created.

POST `/subscriptions/trial` checks trial configuration and historical offer eligibility. Trial invoices are void; no recurring payment authorisation is fabricated. POST `/checkout` for a pending/trial subscription returns an allowlisted hosted form action and signed fields. The browser posts to PayFast; it cannot set active status. A second pending checkout is blocked and requires confirmation/reconciliation or support.

POST `/subscriptions/{id}/change-preview` calculates a new period-end agreement. POST `/subscriptions/{id}/change` requires Idempotency-Key and the exact preview quote_hash, rejects stale price confirmations, persists the intent and synchronises the provider amount/interval/run date. GET `/subscriptions/{id}/changes` returns its state. There is no automatic immediate prorated charge. Failed/ambiguous updates require reconciliation.

POST `/subscriptions/{id}/cancel` synchronises a live provider schedule first. Default cancellation is at period end; an immediate boolean supports immediate termination. Invoice and payment records remain. A new agreement is needed after a terminated provider subscription; a cancelled mandate is not silently resumed.

GET `/payment-methods` lists provider-managed recurring methods by subscription. POST `/payment-methods/setup` returns the provider's official card-update URL for an authorised subscription. No local card vault, CVV or complete card number is accepted.

## Product backend API

POST `/access/check` uses `Authorization: Bearer <service token>` and JSON `organisation_id` / `product`. Tokens are scoped to one product and explicitly authorised organisations, expire after 90 days, and can be revoked immediately. No browser session is accepted as a substitute. Provision via administrator API clients and store the returned token on the product backend.

```json
{
  "version": "1.0",
  "organisation_id": "organisation-uuid",
  "product": "MFAROPOS",
  "allowed": true,
  "subscription_status": "active",
  "plan": "professional",
  "features": {"sales": true, "inventory": true},
  "limits": {"max_users": 10, "max_branches": 3},
  "valid_until": "2026-12-20T12:00:00+00:00",
  "source_subscription_ids": ["subscription-uuid"],
  "cache_ttl_seconds": 30,
  "checked_at": "2026-11-20T12:00:00+00:00"
}
```

On denial, allowed=false, plan/status/valid_until are null, sources empty, and features/limits are empty JSON objects. A missing limit is distinct from an explicit unlimited null cap. Use each feature only when allowed=true and feature=true. Cache per organisation and product. Never retain access beyond valid_until or the response TTL. A fresh denied response invalidates any prior grant. Products should serve their own billing redirect, support and export flows during an outage without granting new privileged paid operations.

POST `/access/usage` uses the same credential and context with metric `transactions` or `storage_bytes`, positive integer quantity and a producer `event_key`. Records and quota checks run under an organisation lock; duplicate identical events do not consume twice, conflicting reuse returns 409. Product user/branch counts are enforced locally against returned caps.

## Responses and pagination

Collections use Laravel pagination: data, current_page, last_page, per_page, total, links. Catalogue arrays and membership lists are direct arrays. Errors contain message; validation errors additionally contain errors mapping field names to arrays. 401 unauthenticated, 403 forbidden, 404 absent/inaccessible record, 409 conflict/overlap/quota, 422 invalid input, 429 rate limit, 502 provider failure and 503 unconfigured service. Session expiry can return 419 for CSRF failure; refresh the session and sign in again.

The API has a default 120 requests/minute browser route limit and a stricter login limit. Tune limits for real product traffic and separate client quotas before large-scale rollout. Payment webhooks require form-encoded provider fields and do not use browser CSRF. Their security comes from the full provider verification procedure.
