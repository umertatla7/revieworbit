# Customer engagement and plan entitlements

## Page responsibilities

- **Visits** is the tenant-scoped history of POS, API, and manual visits. Manual entry is available only from a dialog. A visit detail shows automation decisions, delivery status, the encrypted message snapshot, and **Review link clicked** activity.
- **Review links** lists opaque tracking links, delivery activity, and click metrics. A manager may send a controlled custom follow-up; the API rechecks consent, suppression, messaging configuration, destination ownership, and message credits before sending.
- **Message templates** select one location and one active review destination. The selected destination is resolved server-side when an expiring tracking link is created.
- **Automation rules** own post-visit timing. Each step selects a location-compatible template. Follow-up delays are measured from visit completion, and a follow-up marked `cancel_after_click` is cancelled before sending when an earlier link was clicked.

Tracking never represents a submitted review. ReviewOrbit records redirect clicks only.

## Package configuration

The platform plan catalogue controls location, message-template, automation, automation-step, personalized-media, message-credit, and review-provider allowances. Limits are enforced in the API; hiding a control in the UI is not an entitlement check.

Message credits are debited per delivery using the plan's channel units. SMS units account for encoded segments. MMS and WhatsApp have separately configurable units. A plan can block sending when included credits are exhausted or permit overage.

Admin usage reports aggregate tenant deliveries, delivered/failed counts, credits, and estimated provider cost for the selected month. Provider-cost estimates are stored separately from customer overage price. Estimates default to zero until the platform administrator configures current provider rates; a future reconciliation job may replace them with Twilio's final message price and currency.

## Privacy and tenancy

- Message snapshots use Laravel's encrypted cast and phone numbers remain hash/last-four only in delivery records.
- Every activity, resend, template reference, location, and review destination is scoped by authenticated business membership or an audited support session.
- Cross-tenant IDs return `404`; they are never accepted because a browser supplied a business identifier.
- Resends reuse the source visit and destination but generate a new high-entropy tracking token.
