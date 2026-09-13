# API Contract

Native clients authenticate with `POST /api/v1/auth/mobile/login`, store the returned bearer token in the platform Keychain/Keystore, and revoke it with `POST /api/v1/auth/mobile/logout`. Authenticated tenant requests include `X-Business-ID`; tenant identity is always verified server-side.

Customer review eligibility is updated with `PATCH /api/v1/customers/{customer}/review-status`. Only `review_confirmed` permanently prevents future requests; tracking activity remains labeled **Review link clicked**.

All JSON business APIs are under `/api/v1`; public provider callbacks and `/r/{token}` are deliberately separate. Sanctum cookie sessions protect the first-party web app. Generic integrations authenticate with a one-time-visible hashed API key or HMAC signature.

## Implemented identity, onboarding, administration, and Milestones 3–6 endpoints

- `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, and `GET /api/v1/auth/me` use stateful Sanctum sessions. Registration transactionally creates the owner, business profile, membership, and primary location.
- Authenticated business routes require `X-Business-ID`. The server verifies that identifier against the authenticated user's active membership before deriving tenant context; request bodies cannot select a tenant.
- `GET|PATCH /api/v1/onboarding` and `POST /api/v1/onboarding/complete` expose server-derived onboarding checks. `GET|POST|PATCH /api/v1/pos-integrations` records manual or generic API setup without exposing provider secrets.
- `POST /api/v1/pos-integrations/square/authorize` returns a short-lived Square authorization URL; `GET /api/v1/integrations/square/callback` validates and consumes OAuth state, stores encrypted tokens, and queues the initial import. `POST /api/v1/pos-integrations/{id}/square/sync`, `DELETE /api/v1/pos-integrations/{id}/square`, and `GET /api/v1/square/appointments` provide tenant-scoped resync, revocation, and normalized appointment reads.
- `GET /api/v1/toast/connections` returns only the active tenant's Toast location requests and restaurant mappings. `POST /api/v1/pos-integrations/toast/connect` generates a one-time location code, `DELETE /api/v1/pos-integrations/toast/requests/{id}` cancels it, and `POST /api/v1/pos-integrations/toast/connections/{id}/sync` imports up to 31 days of completed checks. Platform configuration lives under `/api/v1/admin/toast`; verified public webhooks are `/api/v1/webhooks/toast/{environment}/partners` and `/orders`.
- Platform routes under `/api/v1/admin/businesses` require a platform role. `POST /api/v1/admin/businesses` validates and atomically provisions business identity/contact information, an owner membership, a structured primary location, and onboarding defaults; a new owner's password-setup notification may be requested without exposing a temporary password. Tenant mutation by support staff requires a separately created one-hour support session and both `X-Business-ID` and `X-Support-Session`; the reason and subsequent changes are audited.
- `GET|PATCH /api/v1/business`, location creation/update, and invitations cover onboarding and team configuration.
- `GET /api/v1/business` returns server-derived plan entitlements and locations with review destinations. Location creation and destination updates reject limits or providers not included by the tenant's plan, even if a browser bypasses UI controls.
- `GET /api/v1/billing` returns the authenticated tenant's active plan catalogue, synchronized subscription state, and invoice history. Only a tenant owner may create a hosted checkout or customer-portal session through `POST /api/v1/billing/checkout` and `POST /api/v1/billing/portal`; admin support sessions cannot open private billing sessions.
- Super administrators configure encrypted Stripe credentials at `/api/v1/admin/stripe`, create and update plans under `/api/v1/admin/plans`, and explicitly synchronize immutable Stripe prices with `POST /api/v1/admin/plans/{plan}/stripe-sync`.
- `POST /api/v1/webhooks/stripe` verifies the raw Stripe signature and environment before idempotently synchronizing subscriptions and invoices. Browser-provided plan or subscription status is never trusted.
- Customer collection/detail/create/update, explicit consent, suppression, and bounded import routes are tenant-scoped.
- Template collection/create/update/preview and media upload/temporary URL routes are tenant-scoped. Template preview accepts only the documented variable allow-list and always requires `{{review_link}}`.
- Reusable media templates and generated media use private storage; generation is queued on `media`, and only short-lived signed URLs are returned.
- Manual visits and automation rules live under `/api/v1/visits` and `/api/v1/automations`. Every evaluation persists `scheduled` or `skipped` plus a stable reason code.
- `POST /api/v1/integrations/generic/events` accepts a bearer integration key plus `Idempotency-Key`. `POST /api/v1/webhooks/generic` accepts `X-ReviewOrbit-Key`, a Unix `X-ReviewOrbit-Timestamp`, and an HMAC-SHA256 `X-ReviewOrbit-Signature` over `{timestamp}.{raw_body}` with a five-minute tolerance.
- Integration bearer keys are stored only as SHA-256 hashes. HMAC secrets are encrypted because verification requires the original secret. Both are displayed only in the creation response and can be revoked.

Successful collections use `data`, `links`, and `meta`; filters and sort fields are allow-listed. Cursor or page pagination is resource-specific and documented in OpenAPI. Mutation endpoints use proper 201/202/204 responses.

Errors use:

```json
{"message":"The request could not be processed.","code":"VALIDATION_ERROR","errors":{"phone":["The phone number is invalid."]},"request_id":"01..."}
```

The request ID is accepted/generated at the edge and returned in headers and bodies. Domain error codes are stable; messages may evolve.

Create-like integration operations accept `Idempotency-Key`; the server stores a tenant/operation/key fingerprint and refuses conflicting reuse. Webhooks deduplicate on provider event identity.

The normalized generic `visit.completed` payload follows the product specification and is validated before queueing. Webhook signatures cover timestamp plus the exact raw body, use constant-time comparison, enforce a clock tolerance, and support key rotation. OpenAPI is authoritative and generates `packages/api-client`; CI fails on uncommitted generated drift.
