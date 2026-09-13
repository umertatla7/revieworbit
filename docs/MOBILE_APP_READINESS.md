# Mobile app readiness

ReviewOrbit's web and future mobile clients share the versioned Laravel JSON API under `/api/v1`. Domain rules, tenant scoping, plan limits, consent checks, automation timing, and provider integrations remain server-owned; a mobile app must not reproduce them.

## Authentication

- Web uses Sanctum's secure session cookie flow.
- Native apps use `POST /api/v1/auth/mobile/login` with `email`, `password`, and a human-readable `device_name`.
- The returned bearer token is shown once, expires after 90 days, and is stored only in the device's Keychain/Keystore.
- Native apps send `Authorization: Bearer <token>` and the selected membership as `X-Business-ID`.
- `POST /api/v1/auth/mobile/logout` revokes only the current device token.
- Passwords, API credentials, support-session tokens, and provider tokens must never be stored in ordinary app preferences or logs.

## Client contract

The app should consume the OpenAPI-generated client in `packages/api-client`. Mobile screens should use the same APIs as the web dashboard and handle standard `401`, `403`, `404`, `409`, `422`, and `429` responses. IDs are opaque ULIDs, all timestamps are ISO-8601 UTC, and display uses each location's timezone.

Admin-only provider configuration stays outside normal customer navigation. A native customer app must not expose POS or messaging credentials; it may show connection status returned by safe read endpoints.

## Review eligibility semantics

`review_request_status=review_confirmed` is the only customer state that permanently prevents new review-request journeys. A tracking click is recorded only as **Review link clicked** and may stop remaining steps for that visit, but never proves a review was submitted. Provider imports may set a confirmed state only when there is a trustworthy match; otherwise staff or the customer must confirm it explicitly.
