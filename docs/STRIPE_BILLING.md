# Stripe billing

ReviewOrbit uses one platform Stripe account. Each tenant maps to a Stripe Customer and at most one active Stripe Subscription. Card details are collected by Stripe's embedded Checkout iframe; ReviewOrbit never receives or stores card data.

## Configuration

Super administrators configure test or live credentials at `/admin/stripe`. Secret and webhook keys are encrypted at rest and are never returned by the API. Verify the account, configure plans in `/admin/billing`, then synchronize one active plan or all active plans. The admin page explicitly distinguishes the real ReviewOrbit database record from its Stripe publication state: **Not synchronized**, **Partial**, or **Published**. A plan is represented by one Stripe Product and immutable monthly and annual Prices. A price change creates a replacement Price and deactivates the previous Price. Stripe API validation errors are returned as actionable form errors instead of generic server errors.

The integration pins Stripe API version `2026-02-25.clover`, following Stripe's versioning guidance: <https://docs.stripe.com/api/versioning>.

Create a Stripe webhook endpoint for `https://api.revieworbit.tech/api/v1/webhooks/stripe` and subscribe to:

- `checkout.session.completed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.finalized`
- `invoice.paid`
- `invoice.payment_failed`
- `invoice.voided`

Copy the endpoint signing secret into ReviewOrbit. Requests are verified against the raw body with a five-minute timestamp tolerance and persisted idempotently before processing.

## Customer lifecycle

New subscribers use Stripe Embedded Checkout inside ReviewOrbit. `payment_method_collection=always` ensures a card is collected even when a free trial applies. Existing subscribers confirm an upgrade or downgrade in ReviewOrbit; the API replaces the Stripe subscription item price and asks Stripe to calculate prorations. The customer billing screen has Overview, Plans, Payment methods, and Invoices tabs. The assigned plan has a clear **Current plan** state, while alternatives are labeled **Upgrade** or **Downgrade** based on price. Payment methods are masked, invoices are retrieved from Stripe, and **Add/Change payment method** uses an embedded Stripe setup session. The setup-completed webhook makes the new card the invoice default without exposing card data to ReviewOrbit. General Customer Portal access remains available for cancellation and exceptional account servicing.

Public signup is a short three-step flow: business owner details, plan selection, and embedded Stripe payment. The workspace is provisioned before Checkout so the Stripe Customer and subscription are always linked to a tenant. An abandoned checkout leaves billing inactive and can be resumed from Plan & billing. Trials require a payment method and Stripe applies the configured charge only after the trial.

The Stripe webhook is authoritative for subscription status, current plan, billing interval, trial dates, renewal dates, cancellation state, and invoices. `trialing`, `active`, and `past_due` subscriptions retain plan entitlements; payment recovery policy remains configured in Stripe. Every business also has a local plan association so manually assigned and test plans use the correct entitlements before paid checkout.

An administrator may open Checkout or Stripe's hosted payment-method flow only from an active, time-limited support session. The support reason, acting administrator, and billing action are audited. Payment details must be customer-authorized and are entered directly into Stripe; ReviewOrbit never receives or stores the card number.
