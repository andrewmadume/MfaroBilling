# Central identity and SSO

Identity: `https://accounts.mfarosoftware.africa`. Billing API is an OIDC confidential client; customer and admin SPAs use its host-only BFF session. Each independently deployed product registers a separate client and uses its own backend session. A central IdP session enables subsequent logins without sharing application cookies.

## Provider deployment

Run a supported Keycloak release on a VPS/container-capable host or use a managed OIDC provider supporting discovery, authorization code, S256 PKCE, JWKS signature verification, verified-email claims, MFA and backchannel logout. Typical cPanel shared hosting cannot safely run the Java-based Keycloak service. Point `accounts` DNS to the external service, provision TLS, restrict its management console, and use its own supported production database. Do not run development-mode Keycloak in production or place identity databases in billing's public tree.

The included realm JSON is a configuration template, not a complete identity deployment. Import it into an administrator-controlled Keycloak instance. Generate the billing client's secret using the provider console and put it only in the private API environment. Configure SMTP and test registration, verification and reset email delivery. Require OTP or WebAuthn for administrators through the identity provider's authentication flows; the realm template alone does not enforce that policy. Test recovery codes and account recovery before launch.

## Exact billing settings

| Setting | Value |
|---|---|
| Issuer | https://accounts.mfarosoftware.africa/realms/mfaro |
| Client ID | mfaro-billing |
| Client type | Confidential server client |
| Flow | Authorization Code, S256 PKCE |
| Redirect URI | https://api.billing.mfarosoftware.africa/api/v1/auth/callback |
| Backchannel logout | https://api.billing.mfarosoftware.africa/api/v1/auth/backchannel-logout |
| Post-logout URI | https://billing.mfarosoftware.africa/ |
| Account console | https://accounts.mfarosoftware.africa/realms/mfaro/account |

Set OIDC_ISSUER, OIDC_CLIENT_ID, OIDC_CLIENT_SECRET, OIDC_REDIRECT_URI and OIDC_ACCOUNT_URL. Allow exact redirect URIs only. Do not use wildcard callbacks. Client secrets never go into VITE variables. Disable implicit and direct password grants. Configure email/profile claims to appear in signed ID tokens, including email_verified=true only after verification.

`jumbojett/openid-connect-php` handles discovery, authorization code exchange, PKCE, state, nonce, issuer/audience and signature verification. The Laravel adapter persists protocol state in encrypted framework sessions and uses framework redirects so cookies are delivered correctly. Identity links use issuer+subject, not a mutable email. An existing email collision requires support to explicitly link the verified identities; it cannot automatically take over an account.

RP logout invalidates the local session and requests central sign-out using the provider's discovered endpoint. Signed backchannel logout verifies the upstream token, checks fresh iat, required event and jti, rejects replays, and revokes matching local sessions by provider sid or subject. Configure and acceptance-test this endpoint at the provider. Billing-session revocation is also available in Account & security. Provider console revocation and MFA configuration remain IdP responsibilities.

## Other product clients

Register `mfaropos`, `mfaroinvoice`, `mfarohr`, `mfaroedu` and `mfarobooking` independently. Configure each product's real, existing HTTPS backend callback; the product subdomains were not supplied and must not be presumed to exist. Add the required DNS and certificates before registering callbacks. Use Authorization Code + PKCE with a maintained OIDC library for each language. Validate identity and local membership first, then call the billing entitlement API from the backend.

Production SSO, MFA, registration and signed logout have not been exercised against a configured live provider in this development run. Automated tests cover integration boundaries, disabled local auth, redirect/session adapter behaviour, and access control. The full multi-client SSO acceptance scenario remains mandatory.

Sources: [Keycloak OIDC](https://www.keycloak.org/securing-apps/oidc-layers), [OIDC client library](https://github.com/jumbojett/OpenID-Connect-PHP), [OAuth security BCP](https://www.rfc-editor.org/rfc/rfc9700.html).
