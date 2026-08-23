# Product Requirements

## Vision and value

ReviewOrbit helps small businesses consistently ask consenting customers for honest Google feedback after eligible completed activity. It automates timing, personalization, delivery, suppression, and measurement while preserving a truthful distinction between a link click and a verified review.

## Users

- Super admins operate the platform and audited support workflows.
- Platform managers assist assigned businesses without secret access.
- Business owners configure their organization, integrations, messaging, and team.
- Business managers operate assigned locations and permitted campaigns.
- Business viewers read dashboards and reports.
- Customers receive messages, follow opaque review links, and can opt out.

## Core journey

AL Barber Shop connects Square or uses manual/custom input. When Umer's Main Street haircut payment completes, ReviewOrbit resolves his business-scoped profile and consent, applies suppression/frequency/quiet-hour rules, sends the approved request through a queue, records delivery, and records a **Review link clicked** event before redirecting to the configured Google review URL.

## Functional scope

Phase 1 includes secure authentication, tenants/roles/invitations/locations, onboarding, customers/consent/suppression/import, manual visits, generic signed events, templates/media, automation/follow-ups, queued SMS/MMS, tracking, analytics, audits, and operational views. Phase 2 adds Square Sandbox OAuth, location mapping, verified durable webhooks, completed-payment processing, customer sync, booking context, revocation, and QA fixtures.

## Non-functional requirements

The product must be tenant-isolated, accessible, responsive, observable, idempotent, horizontally worker-scalable, UTC-correct, privacy-minimizing, recoverable, documented through OpenAPI, and covered by unit, feature, integration, and end-to-end tests. Valid webhooks are acknowledged without slow provider calls.

## Out of scope

Billing, agencies/white-labeling, native mobile apps, email/WhatsApp/loyalty campaigns, non-Square POS connectors, review monitoring/display/replies, sentiment routing, rating gates, incentives, and verified Google review attribution are excluded from Phases 1–2.
