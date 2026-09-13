# Review confirmation and future eligibility

ReviewOrbit distinguishes three facts:

1. **Message delivered** comes from the messaging provider.
2. **Review link clicked** comes from ReviewOrbit's opaque tracking redirect.
3. **Review confirmed** requires an explicit confirmation or a trustworthy provider-side match.

A click never means a review was submitted. Google Business Profile can publish new-review notifications through Cloud Pub/Sub and its API can list reviews for a verified location, but review records do not provide the POS customer's phone number or email. Reviewer names can be missing, changed, or shared, so name-only matching must not permanently suppress a customer.

Official references:

- Google Business Profile review list: https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews/list
- Google Business Profile real-time notifications: https://developers.google.com/my-business/content/notification-setup

## Current behavior

- A link click may cancel the remaining follow-ups for that visit.
- The automation frequency window prevents repeated requests for a configurable number of days.
- `review_request_status=review_confirmed` cancels pending automation dispatches and permanently skips new review-request journeys for that business/customer.
- An owner or audited admin support session can confirm a review or restore eligibility from the customer activity dialog.
- Provider integrations may set `review_confirmed` only when a strong, documented identifier exists. Ambiguous Google reviews remain unmatched for staff review.

## Google Business Profile phase

The future Google connection should use OAuth per business, map each ReviewOrbit location to a verified Business Profile location, persist Pub/Sub events idempotently, and fetch the referenced review. It should present suggested matches to staff rather than silently matching by display name. A customer-facing confirmation page can provide a second reliable confirmation path without claiming that a click itself is a review.
