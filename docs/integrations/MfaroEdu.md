# MfaroEdu integration template

Product code: `MFAROEDU`. Register a separate OIDC client at Mfaro Accounts with this product's actual HTTPS backend callback. No product hostname has been assumed.

Store BILLING_API_URL and a credential scoped to MFAROEDU on the trusted backend. The backend must authenticate the user, validate organisation membership, and call the reusable PHP or Node module before paid operations. For the primary workflow enforce feature `students` as well as allowed=true and valid_until. Apply every other feature flag and usage cap relevant to the product; see PRODUCT_INTEGRATION.md and the seeded feature catalogue.

On each organisation switch invalidate entitlement caches. Recheck within 30 seconds, never extend stale access indefinitely, and provide an appropriate export/support/billing route when paid access is denied. Use your own database for all operational records. Do not place service tokens in browser code.

Acceptance: verified central login, own-organisation access, foreign-organisation denial, independent and bundle access, missing-feature denial, cap enforcement, cancellation, outage handling and restoration. This template is not a completed integration with an existing product repository.
