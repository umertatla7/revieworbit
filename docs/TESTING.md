# Testing Strategy

Backend Pest tests cover domain units and HTTP/queue/database features, including authentication, roles, invitations, suspension, tenant isolation, validation, consent/suppression, imports, templates, scheduling/idempotency, fake messaging, callbacks/opt-outs, tracking, generic HMAC, and Square mapping/signatures/revocation. Every tenant resource includes cross-business read and mutation attempts.

Frontend Vitest and React Testing Library tests cover forms, navigation, permissions, previews, integration state, dashboards, and errors. Mock Service Worker may emulate the documented API only in tests and Storybook-like development contexts.

Playwright covers the AL Barber Shop/Umer journey end to end. Provider traffic is replaced by fakes or deterministic fixtures in CI; production message credentials are absent and the application refuses non-fake providers in the test environment.

CI runs formatting, PHP static analysis/tests, migrations on PostgreSQL, web lint/type/unit tests, OpenAPI generation diff, Playwright, dependency audits, and production builds. Flaky tests are treated as failures to fix, not retried into invisibility.
