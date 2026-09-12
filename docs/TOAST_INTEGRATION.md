# Toast POS integration

## Connection model

Toast is a partner integration, not a separate OAuth application created by every ReviewOrbit customer. ReviewOrbit obtains one machine-client credential pair for each Toast environment through the [Toast integration development process](https://doc.toasttab.com/doc/devguide/integrationDevProcess.html). Toast provides the API hostname; it must be saved in the platform configuration rather than inferred or hard-coded.

A store owner connects each restaurant location from Toast Web:

1. Select the matching ReviewOrbit location and generate a one-time connection code.
2. Open the ReviewOrbit listing in Toast My Integrations, select a Toast location, and paste the code into Toast's Location ID (`externalRestaurantRef`) field.
3. Toast sends a signed `partner_added` event containing its restaurant GUID and the connection code.
4. ReviewOrbit consumes the code once, maps the restaurant to the correct business and location, and queues an initial order sync.

The same ReviewOrbit partner token is reused for authorized restaurants. Restaurant API calls include `Toast-Restaurant-External-ID`; no customer is given ReviewOrbit's Toast client secret. When Toast sends `partner_removed`, ReviewOrbit marks the location disconnected and stops API calls. See Toast's [accessible restaurants flow](https://doc.toasttab.com/doc/devguide/apiPartnersGettingAccessibleRestaurants.html) and [machine-client authentication](https://doc.toasttab.com/doc/devguide/authentication.html).

## Platform administrator setup

Request sandbox partner access from Toast and ask for these least-privilege scopes:

- `orders:read` for completed order and check details.
- `guest.pi:read` to retrieve guest contact data from the Orders API.
- `restaurants:read` for authorized restaurant identity and configuration.

In Admin > Toast POS setup, save the Toast-provided API base URL, client ID, client secret, partner webhook secret, and orders webhook secret. Add a marketplace or Toast Web integration URL when Toast provides one. Register the exact partner and order webhook URLs shown on that screen. Verify API credentials, then perform a sandbox partner add/remove and completed-order test before production approval.

Secrets are encrypted at rest and are never returned to the browser. Tokens are cached and reused until shortly before expiry to respect Toast's [authentication rate limits](https://doc.toasttab.com/doc/devguide/apiAuthenticationRateLimit.html).

## Webhooks and order import

ReviewOrbit validates `Toast-Signature` using base64-encoded HMAC-SHA256 over the exact raw request body followed by the payload timestamp, as specified by [Toast message signing](https://doc.toasttab.com/doc/devguide/apiMessageSigning.html). A verified event is persisted before queue dispatch and deduplicated on `(provider, external_event_id)`. The HTTP endpoint responds immediately with `202`; asynchronous jobs may retry safely.

The [orders webhook](https://doc.toasttab.com/doc/devguide/devOrdersWebhookRef.html) does not include guest PII. For an order update, ReviewOrbit retrieves the full order using `GET /orders/v2/orders/{orderGuid}` and imports each non-voided closed check as one visit. Historical backfill uses `GET /orders/v2/ordersBulk`, limited to 31 days per user-triggered sync. Future updates rely on webhooks.

Toast is modeled as a restaurant POS, not an appointment system. Only closed/paid checks are visits. Open orders are ignored. Voided orders mark matching visits voided and cancel still-scheduled automation decisions.

Guest phone/email information may match or create a customer record, but it never creates messaging consent. Missing guest/contact data is a normal outcome. Existing automation evaluation then produces `customer_missing`, `phone_missing`, or `consent_missing` rather than sending a message.

## Operational checks

- `partner_added` maps only an unexpired, hashed location code and cannot reassign a restaurant across tenants.
- All customer endpoints scope requests and restaurant connections by the authenticated business context.
- Store provider payloads only in the restricted webhook intake record; customer-facing responses expose sanitized status fields.
- Monitor failed verified events on Admin > Toast POS setup and retry through the same idempotent processing path.
- Keep sandbox and production credentials, signing secrets, webhooks, and location mappings separate.
- Never describe a tracked click as a submitted review; it is only a **Review link clicked** event.
