# Customer engagement and plan entitlements

## Page responsibilities

- **Customers & activity** is the tenant-scoped directory for customers, POS/API/manual visits, SMS delivery, and **Review link clicked** activity. Its filters cover visit recency, missing visits, link clicks, links not clicked, source, consent, and suppression. The customer detail dialog includes full visit/link history, reversible manual SMS suppression, and a controlled custom follow-up. The API rechecks consent, suppression, messaging configuration, destination ownership, and monthly customer allowance before starting a review journey.
- **Message templates** select one location and one active review destination. The selected destination is resolved server-side when an expiring tracking link is created.
- **Automation rules** own post-visit timing. Each step selects a location-compatible template. Follow-up delays are measured from visit completion, and a follow-up marked `cancel_after_click` is cancelled before sending when an earlier link was clicked.

Tracking never represents a submitted review. ReviewOrbit records redirect clicks only.

## Package configuration

The platform plan catalogue controls monthly unique customers, locations, message templates, automations, automation steps, personalized media, review destinations, and review-provider allowances. Limits are enforced in the API; hiding a control in the UI is not an entitlement check.

Launch includes 100 customers per month, one location, and one review destination chosen from any supported provider. Momentum includes 300 customers and one location. Expansion includes 400 customers shared across two locations, with separate location destinations and combined reporting. Enterprise uses administrator-configured custom limits. Changing or deleting a template archives it so historical deliveries keep their original relationship and audit trail.

The allowance counts a distinct customer once when their first review request is created in the month. Later follow-up messages to that customer are included. When the allowance is exhausted, new customer journeys pause with an upgrade prompt; ReviewOrbit does not add surprise overage charges.

Admin usage reports aggregate unique customers, deliveries, delivered/failed counts, and internal provider cost for the selected month. Provider cost is operational information for ReviewOrbit administrators and is never presented as a customer overage price.

## Privacy and tenancy

- Message snapshots use Laravel's encrypted cast and phone numbers remain hash/last-four only in delivery records.
- Every activity, resend, template reference, location, and review destination is scoped by authenticated business membership or an audited support session.
- Cross-tenant IDs return `404`; they are never accepted because a browser supplied a business identifier.
- Resends reuse the source visit and destination but generate a new high-entropy tracking token.
