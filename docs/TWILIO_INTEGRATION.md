# Twilio SMS and WhatsApp Integration

## Sender architecture

ReviewOrbit uses Twilio's preferred ISV isolation model: one Twilio subaccount and one Messaging Service per ReviewOrbit business. The Messaging Service owns that business's approved sender pool, compliance registration, opt-out behavior, and delivery analytics.

This means:

- A business does not enter an arbitrary `From` number.
- SMS can use a Twilio number, toll-free sender, or other sender that has been added to the business's Messaging Service and completed all applicable registration.
- WhatsApp uses a separately approved WhatsApp sender associated with that business.
- A shared platform number is acceptable only for local or tightly controlled testing. It is not the production tenant model because reputation, opt-outs, and branding would be shared.
- Platform staff can configure these values on behalf of a customer only through the existing audited support-session workflow.

Twilio references: [Messaging Services](https://www.twilio.com/docs/messaging/services), [ISV A2P 10DLC onboarding and preferred subaccount architecture](https://www.twilio.com/docs/messaging/compliance/a2p-10dlc/onboarding-isv), and [WhatsApp sender onboarding](https://www.twilio.com/docs/whatsapp/self-sign-up).

## Channel rules

SMS and WhatsApp consent are stored independently. Consent imported with a POS contact or appointment is never assumed. A message is sent only when all of these are true:

1. The visit produced an eligible, due automation dispatch.
2. The business's Twilio configuration is verified and the selected channel is enabled.
3. The customer has current consent for that exact channel.
4. The customer is not suppressed for that channel.
5. The location has a Google review destination and the template is active.

Business-initiated WhatsApp messages outside the 24-hour customer service window require an approved Twilio Content Template. ReviewOrbit therefore requires a `HX...` Content SID for active WhatsApp templates. The supported Content variables are `1` customer first name, `2` business name, and `3` the opaque ReviewOrbit link. See Twilio's [WhatsApp API and template rules](https://www.twilio.com/docs/whatsapp/api).

### Optional personalized image on SMS

The customer-facing template editor exposes SMS only. Attaching a personalized image is optional; Twilio transports an SMS with an image as MMS, but ReviewOrbit does not require the customer to manage a separate channel. Both plain SMS and SMS with an image use the customer's SMS consent and the business's SMS-enabled Messaging Service. When the option is enabled, the template must select one personalized-media template. At delivery time, the messaging queue renders a new JPEG for that recipient, automatically reduces the selected font size until the name fits the configured safe area, stores the result privately, and passes Twilio a high-entropy media URL. The URL reveals no customer information, expires with the generated file after 30 days, and serves only the generated image with a fixed media content type. Legacy WhatsApp records remain available to administrators for audit/history, but WhatsApp setup and authoring are not exposed in the customer dashboard.

Businesses upload 600–4096 px JPG, PNG, or WebP backgrounds up to 20 MB; 1080 × 1080 px is the recommended authoring size. ReviewOrbit suggests a low-detail text area, but the customer remains responsible for confirming it in the visual editor. The editor stores four normalized corner points so photographed signs can be skewed or angled. The renderer wraps text to the configured line limit, measures it with the selected font, shrinks it when necessary, and perspective-warps the text layer into the selected quadrilateral. Fonts are restricted to the server allowlist and arbitrary font files or paths are never accepted.

## Platform credentials

Production super administrators configure Twilio from **Admin → Twilio setup**. The Account SID is stored as an identifier and the Auth Token is encrypted with Laravel's application key. The token is write-only: it is never returned to the browser, included in audit changes, or logged. Saving credentials returns them to draft status; a separate live verification request is required before ReviewOrbit can use them.

Environment values remain supported as a deployment fallback:

Keep these only in `apps/api/.env` or a production secret manager:

```dotenv
MESSAGING_PROVIDER=twilio
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=replace-with-secret-manager-value
TWILIO_STATUS_CALLBACK_URL=https://api.example.com/api/v1/webhooks/twilio/status
TWILIO_INBOUND_WEBHOOK_URL=https://api.example.com/api/v1/webhooks/twilio/inbound
TRACKING_BASE_URL=https://api.example.com
```

The account SID and auth token are platform-level credentials. Per-business subaccount and Messaging Service SIDs, approved sender display values, and channel enablement are configured in **Customer dashboard → Messages**. Tokens are never stored per tenant.

Keep `MESSAGING_PROVIDER=fake` for local development and automated tests. The fake provider records the same delivery lifecycle but performs no network request.

### Trial testing

Trial mode is a temporary exception to the production subaccount architecture:

1. Save the Trial Account SID and Auth Token in **Admin → Twilio setup**, select **Trial testing**, and verify the connection.
2. Create a Messaging Service in that same Trial account and add its Trial SMS sender.
3. In the test business's **Messages** screen, use the Trial Account SID in the account field, add the Messaging Service SID and approved sender, then verify the workspace.
4. Add the tester as a customer, record real SMS consent, and verify that exact phone number in the Twilio Console.
5. In **Message templates**, select **Send test message**, choose the consented tester, and confirm the Twilio Trial verification.

Test delivery is rate limited and audited. It creates a separate test-delivery record, uses only a tenant-scoped customer ID, stores only a phone hash and final four digits in delivery history, prefixes SMS text with `[ReviewOrbit test]`, and never creates a review-click record. Trial restrictions can still cause Twilio to reject custom content or unverified destinations; the rejection code is shown without exposing credentials.

## Twilio Console setup per business

1. Create a dedicated subaccount for the ReviewOrbit business.
2. Create a Messaging Service inside that subaccount.
3. Add only approved SMS senders to its sender pool and complete the registrations applicable to the destination countries (for example US A2P 10DLC or toll-free verification).
4. Enable Advanced Opt-Out and point incoming-message handling to `TWILIO_INBOUND_WEBHOOK_URL`.
5. Configure the business's WhatsApp sender through the Twilio/Meta onboarding flow when WhatsApp is required.
6. Create and receive approval for the WhatsApp Content Templates used by ReviewOrbit.
7. Enter the subaccount SID, Messaging Service SID, and approved sender values in the business workspace. Save, then select **Verify & activate**.

ReviewOrbit supplies `TWILIO_STATUS_CALLBACK_URL` on every outgoing message and verifies `X-Twilio-Signature` before accepting either callback. See Twilio's [webhook security guidance](https://www.twilio.com/docs/usage/webhooks/webhooks-security).

## Workers and scheduler

Both processes are required for automatic delivery:

```bash
cd apps/api
php artisan queue:work --queue=messaging,default --tries=3 --timeout=90
php artisan schedule:work
```

Production should supervise both processes and restart them on failure. The scheduler finds due dispatches each minute; queue jobs are idempotent because each automation dispatch can have only one delivery record.

## Events and privacy

- Delivery callbacks update queued, sent, delivered, failed, or undelivered state.
- STOP-family replies create a channel-specific suppression; START/UNSTOP releases it and records provider evidence.
- Operational views store and display only a phone hash and the final four digits.
- The tracking endpoint records **Review link clicked** and redirects to Google. It never claims a review was submitted.
- Raw credentials, authorization headers, full phone numbers, and Twilio payload PII must not be logged.
