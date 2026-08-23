# ReviewOrbit Engineering Guide

## Purpose

ReviewOrbit is a multi-tenant SaaS that requests honest Google feedback after an eligible customer visit. A tracking click is always described as **Review link clicked**, never as a submitted review. Review gating, incentives, fabricated customers, and messaging without consent controls are prohibited.

## Architecture and directories

- `apps/api`: Laravel 13 modular-monolith JSON API, queue workers, scheduler, and provider webhooks.
- `apps/web`: Next.js 16 App Router application using strict TypeScript.
- `packages/api-client`: generated TypeScript client from the OpenAPI contract.
- `docs`: product, architecture, security, compliance, integration, test, and deployment decisions.
- `infrastructure`: container configuration and operational scripts.

PostgreSQL is authoritative. Redis backs cache and queues. S3-compatible private storage holds media. Laravel owns all domain rules; the web application does not reproduce them.

## Commands

Run `make help` for the full list. Common commands are `make setup`, `make up`, `make migrate`, `make seed`, `make test`, `make lint`, `make typecheck`, and `make build`. Within the API use `composer test` and `composer lint`; within the web app use `npm test`, `npm run lint`, and `npm run typecheck`.

## Naming and organization

- PHP follows PSR-12 and Laravel conventions. Use domain folders under `app/Domain`; prefer actions/services over repository layers without a demonstrated need.
- TypeScript is strict. React components use PascalCase, hooks use `useCamelCase`, and route folders use kebab-case.
- REST resources are plural and versioned under `/api/v1`.
- IDs are ULIDs. Timestamps are stored in UTC; business/location time zones determine display and scheduling.
- Provider payloads stay behind connector DTOs. They must not leak into automation or messaging domains.

## Tenant safety

- Every tenant-owned table has a non-null `business_id`, except intake records that are explicitly unresolved.
- Derive the tenant from the authenticated membership or verified integration connection. Never trust browser-provided `business_id`.
- Scope route-model binding and all queries by tenant. Policies remain mandatory even when the UI hides an action.
- Every tenant feature requires tests proving Business A cannot read or mutate Business B data through IDs, URLs, filters, or request bodies.
- Cross-tenant platform access must be explicit, role-checked, and audited.

## Security

- Never commit credentials. Tokens are encrypted at rest; API keys are stored only as hashes and shown once.
- Never log credentials, raw authorization headers, passwords, full phone numbers, email addresses, or provider payload PII.
- Verify raw webhook bodies before dispatch, enforce replay/idempotency constraints, and acknowledge valid webhooks quickly.
- Validate uploads by size, MIME type, and dimensions. Private storage and signed URLs are the default.
- Tracking tokens are high-entropy random values containing no PII or sequential identifiers.
- Automated tests always bind the fake messaging provider and cannot contact Twilio.

## Migrations

- Migrations are forward-only after review; never edit an applied shared migration.
- Add foreign keys, explicit deletion behavior, indexes supporting tenant filters, and tenant-scoped unique constraints.
- Destructive schema changes require a documented expand/migrate/contract rollout and rollback plan.
- Prefer portable PostgreSQL types; JSONB is reserved for provider payloads or deliberately flexible configuration.

## Integrations and webhooks

- Implement providers behind `IntegrationConnector` and `MessagingProvider` contracts.
- Pin external API versions and cite official documentation in the integration document.
- Persist a verified webhook event before asynchronous processing. Uniqueness is `(provider, external_event_id)`.
- Jobs must be idempotent and safe to retry. Manual reprocessing uses the same code path and is audited.
- Missing customer/contact data is an expected skip outcome, not an exception.

## Definition of done

A change is complete only when persistence, validation, authorization, tenant isolation, errors, audit behavior, tests, and documentation are included; relevant tests, formatting, linting, type checking, and production builds pass; no secrets or production messages are emitted; and the feature is usable from the UI or documented API.
