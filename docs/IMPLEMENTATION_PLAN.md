# Implementation Plan

## Repository discovery

The repository was empty on 2026-08-04 and was not initialized as Git. There is no reusable code or configuration. The supplied product specification is the source of truth, and a new monorepo will be created without microservices.

## Pinned baseline

| Component | Baseline | Rationale |
|---|---:|---|
| PHP | 8.4 | Supported by Laravel 13 and available locally |
| Laravel | 13.x | Current stable framework with security support through March 2028 |
| Node.js | 24 LTS in containers | Stable deployment runtime; host Node may be newer |
| Next.js | 16.3.0 | Current stable release and patched dependency chain at discovery |
| PostgreSQL | 18 | Current stable major; use portable SQL where possible |
| Redis | 8 | Queue and cache backend |
| Square API | 2026-07-15 | Current Square API reference version verified 2026-08-07; explicitly sent in every request |

Lockfiles and container image digests/patch tags are the actual reproducibility boundary. Dependencies are upgraded intentionally with tests and changelog review.

## Milestones and primary files

1. **Discovery and documentation** — `AGENTS.md`, `README.md`, `.env.example`, and `docs/*`.
2. **Development environment** — `apps/api`, `apps/web`, `packages/api-client`, `docker-compose.yml`, `Makefile`, `infrastructure/docker/*`, CI workflow.
3. **Identity and tenancy — completed 2026-08-12** — Sanctum session registration/login/logout, complete transactional self-service and platform-assisted account provisioning, secure owner password setup, verified membership-derived business context, owner/manager/viewer authorization, onboarding, locations, invitations, audits, and authenticated Next.js shell.
4. **Customers and consent — completed 2026-08-05** — tenant-scoped customer and external-identity records, E.164 validation and business-scoped phone hashes, versionable consent evidence, suppression, quoted CSV parsing/preview/result reporting, directory UI, audits, and adversarial isolation tests.
5. **Templates and media — completed 2026-08-05** — SMS/MMS channels, allow-listed variables, required opaque review-link placeholder, duplication/archive support, GSM/UCS-2 segment estimates, reusable private media templates, queued Imagick personalization, expiring generated media, UI, audits, and tests.
6. **Visits and automations — completed 2026-08-05** — manual visits, one-time-visible hashed bearer keys, encrypted HMAC secrets, verified generic webhooks, idempotent normalized events, rules/follow-ups, explicit eligibility decisions, quiet-hour adjustment, frequency limits, UI, and tenant-isolation tests.
7. **Messaging and tracking — implemented 2026-08-12** — tenant-isolated Twilio subaccount/Messaging Service configuration, fake/Twilio providers, due-message queue lifecycle, channel-specific SMS/WhatsApp consent and suppression, WhatsApp Content Template enforcement, signed delivery/inbound callbacks, masked delivery history, and opaque review-link click tracking. Live delivery still requires external credentials, approved senders, registrations, and supervised workers.
8. **Analytics and administration — partially implemented 2026-08-06** — separate customer/platform shells, platform business directory, create/suspend/reactivate controls, onboarding/POS health summaries, and audited one-hour support sessions are complete. Metric queries, audit-log UI, webhook viewer, and failed-processing operations remain.
9. **Square OAuth and initial appointment import — implemented 2026-08-07** — least-privilege OAuth, encrypted refreshable credentials, expiring one-time state, location/customer import, configurable historical/upcoming appointment backfill, manual resync, health summaries, revocation, audited admin-assisted setup, and tenant-isolation coverage.
10. **Square payment webhooks** — raw-body signature verification, durable intake, idempotent queued mapper, visit and automation dispatch.
11. **Continuous Square booking/customer sync** — webhook-driven incremental booking/customer updates, scheduled reconciliation, conflict and limitation reporting. Initial context-only backfill is complete in Milestone 9.
12. **Final verification** — all tests, static analysis, production builds, security/tenancy review, and deployment documentation.

Each milestone remains runnable and is completed backend-first: migration and domain behavior, API contract, generated client, UI, then end-to-end coverage.

## Current execution slice

Milestones 1–7 and Square OAuth/initial appointment import are implemented, plus the customer onboarding/dashboard and tenant-management portion of Milestone 8. Owners can connect a Square Sandbox or production account, import Square locations, customers, and a configurable year of previous/upcoming appointments, inspect sync health, resync, and revoke access. Platform staff can perform the same workflow only inside an audited support session. Customer workspaces can configure isolated Twilio SMS/WhatsApp sending, consent, templates, and masked delivery history; fake delivery remains the safe local default. Continuous Square webhooks remain a separate delivery slice. Imported appointments never create a completed visit, consent, automation dispatch, or message.

Both role surfaces now expose the complete planned product information architecture through responsive grouped sidebars. Implemented modules link to working screens; future modules have honest static screens with disabled actions, intended metrics, capability boundaries, and milestone status so subsequent work can proceed module by module without misleading users.

The customer module now includes a plan-aware multi-location workspace with editable structured location details and provider-neutral review destinations. The customer directory uses a paginated table, modal manual entry, channel-specific consent state, CSV validation preview, downloadable sample, staged progress, error reporting, and audited import results.

## Assumptions

- Businesses are the tenant boundary; a user may belong to multiple businesses.
- Consent is business-specific and is never inferred from provider contact data.
- A completed payment is the default automatic visit trigger. Booking updates alone do not trigger messages.
- Fake messaging is the local default. Twilio and Square production modes require explicit environment configuration.
- The web and API share one registrable domain in production so Sanctum cookie authentication is reliable.
- PostgreSQL, Redis, MinIO, and Mailpit are locally containerized; production deployments may use managed equivalents.
- Personalized images use PHP Imagick because it is mature, supports server-side text composition, and is available as a controlled container extension. Generation is queued and failure falls back to SMS.

## Risks and mitigations

- **Consent/legal variation:** retain evidence and configurable controls; document that businesses remain responsible for lawful use.
- **Provider schema drift:** pin versions, isolate DTO mapping, retain minimized webhook payloads, and run fixture contract tests.
- **Cross-tenant leakage:** tenant-derived queries, scoped binding, policies, composite uniqueness, and adversarial tests.
- **Duplicate delivery:** unique provider events, visit/rule dispatch keys, idempotency keys, and transactional job boundaries.
- **Quiet-hour errors:** store UTC, calculate in location/business IANA zones, and test daylight-saving boundaries.
- **PII exposure:** log redaction, private media, opaque links, retention jobs, and narrow operational views.
- **MMS/media failure:** asynchronous generation, expiry, retry limits, and SMS fallback.

## Missing external configuration

Square application ID/secret, Sandbox credentials and webhook signature key; Twilio account/auth credentials, per-business subaccounts, Messaging Services, approved SMS/WhatsApp senders, and registrations; production S3 credentials/domain; mail provider; public callback URLs; error monitoring; and deployment/backup targets are not available. They must be supplied through environment or secret-management systems and are never committed.

## Verification gates

Every milestone runs backend tests and formatting, frontend unit tests/lint/type checking, OpenAPI client generation consistency, and affected Playwright flows. CI additionally performs production builds and dependency/security audits. Phase 2 tests use recorded synthetic fixtures and mocks unless explicitly running an opt-in Sandbox suite.
