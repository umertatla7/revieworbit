# Twilio SMS and WhatsApp Integration

## Sender architecture

B Review supports three explicit sender modes:

1. `platform_shared` — the business inherits the B Review-owned default Messaging Service and number. Platform credentials remain invisible to tenants. This mode may be enabled only after a super administrator records that Twilio approved the Brand/Campaign for the intended use.
2. `platform_dedicated` — B Review assigns a separate account/subaccount, Messaging Service, and number. A customer can request this mode, but only an administrator in an audited support session can assign its SIDs and sender.
3. `customer_owned` — the business supplies its own Account SID, Auth Token, Messaging Service, and approved number. Its Auth Token is encrypted, write-only, and tenant-scoped.

Twilio's preferred production ISV isolation model remains one subaccount and one Messaging Service per customer business. A shared sender is an intentional commercial option, not a compliance bypass. Twilio can reject a campaign that represents multiple unrelated companies, so the platform refuses to expose a shared sender until its service, sender pool, credentials, and recorded compliance acknowledgement pass verification.

This means:

- A business does not enter an arbitrary `From` number.
- SMS can use a Twilio number, toll-free sender, or other sender that has been added to the business's Messaging Service and completed all applicable registration.
- WhatsApp uses a separately approved WhatsApp sender associated with that business.
- A shared number also shares reputation and provider-level opt-outs. A STOP-family reply therefore suppresses the matching recipient in every active workspace using that shared sender.
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

The platform Account SID and Auth Token are configured only once. The same parent credentials can be used for B Review-managed numbers. A business using `customer_owned` stores its own encrypted Auth Token; the token is never serialized, logged, or shown again. Sender choice and channel enablement are configured in **Customer dashboard → SMS connection**.

Keep `MESSAGING_PROVIDER=fake` for local development and automated tests. The fake provider records the same delivery lifecycle but performs no network request.

### Trial testing

Trial mode is a temporary exception to the production subaccount architecture:

1. Save the Trial Account SID and Auth Token in **Admin → Twilio setup**, select **Trial testing**, and verify the connection.
2. Create a Messaging Service in that same Trial account and add its Trial SMS sender.
3. In the test business's **Messages** screen, use the Trial Account SID in the account field, add the Messaging Service SID and approved sender, then verify the workspace.
4. Add the tester as a customer, record real SMS consent, and verify that exact phone number in the Twilio Console.
5. In **Message templates**, select **Send test message**, choose the consented tester, and confirm the Twilio Trial verification.

Test delivery is rate limited and audited. It creates a separate test-delivery record, uses only a tenant-scoped customer ID, stores only a phone hash and final four digits in delivery history, prefixes SMS text with `[ReviewOrbit test]`, and never creates a review-click record. Trial restrictions can still cause Twilio to reject custom content or unverified destinations; the rejection code is shown without exposing credentials.

### Operational visibility

- The platform Twilio screen records credential-save, verification-success, and verification-failure events without storing credential values in the log.
- Each business Twilio screen records configuration, verification, and test-message events. Delivery history combines production and test SMS records, marks test traffic, masks recipients to the final four digits, and displays provider failures.
- Invalid form submissions preserve the entered SID and sender values so an administrator can correct the specific field. The Auth Token remains write-only and is cleared from the browser after a successful save.
- New message templates default to active. Draft templates remain visible in the automation builder with an instruction to activate them; an active automation cannot use a draft template.
- Template previews render the current tenant's business and selected location names. Preview links are non-customer example links and do not create tracking records.

## Twilio Console and workspace setup

### B Review default sender

1. In the parent Twilio account, create the Messaging Service and add the purchased number to its Sender Pool.
2. Complete the applicable Brand/Campaign or toll-free verification. Confirm with Twilio that the registered use case covers the businesses and message branding that will use it.
3. In **Admin → Twilio setup**, enter the platform Account SID/Auth Token, default Messaging Service SID, and number. Record the compliance acknowledgement, save, and verify.
4. A business owner can then select **Use the B Review default number**, save, and verify without seeing any credential.

### Dedicated B Review sender

1. Create a dedicated subaccount for the ReviewOrbit business.
2. Create a Messaging Service inside that subaccount.
3. Add only approved SMS senders to its sender pool and complete the registrations applicable to the destination countries (for example US A2P 10DLC or toll-free verification).
4. Enable Advanced Opt-Out and point incoming-message handling to `TWILIO_INBOUND_WEBHOOK_URL`.
5. Configure the business's WhatsApp sender through the Twilio/Meta onboarding flow when WhatsApp is required.
6. Create and receive approval for the WhatsApp Content Templates used by ReviewOrbit.
7. Start an audited support session, select **Dedicated B Review number**, enter the account/subaccount SID, Messaging Service SID, and approved sender, then verify.

### Customer-owned Twilio

1. The business selects **Connect my own Twilio account**.
2. It enters its Account SID, Auth Token, Messaging Service SID, and approved E.164 number.
3. After save, the Auth Token field clears and only a “saved securely” indicator remains.
4. Verification confirms the Messaging Service belongs to that account and the number is present in its sender pool.

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
