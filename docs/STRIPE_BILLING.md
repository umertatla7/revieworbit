# Stripe billing

ReviewOrbit uses one platform Stripe account. Each tenant maps to a Stripe Customer and at most one active Stripe Subscription. Card details are collected by Stripe's embedded Checkout iframe; ReviewOrbit never receives or stores card data.

## Configuration

Super administrators configure test or live credentials at `/admin/stripe`. Secret and webhook keys are encrypted at rest and are never returned by the API. Verify the account, configure plans in `/admin/billing`, then synchronize one active self-service plan or all active self-service plans. The admin page explicitly distinguishes the real ReviewOrbit database record from its Stripe publication state: **Not synchronized**, **Partial**, or **Published**. A paid plan is represented by one Stripe Product and immutable monthly and annual Prices. A price change creates a replacement Price and deactivates the previous Price. Sales-assisted custom plans are tenant-private, synchronized when assigned, and never appear in another tenant's catalogue. Stripe API validation errors are returned as actionable form errors instead of generic server errors.

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

The initial catalogue is Launch ($49/month, 100 customers), Momentum ($99/month, 300 customers), Expansion ($139/month, 400 customers shared across two locations), and configurable Custom. The three fixed packages remain in a three-column comparison; Custom is a separate full-width option that opens a visible modal. A tenant owner enters customer volume, locations, messaging volume, MMS mix, and support needs and sees the quote update automatically. ReviewOrbit then creates a tenant-private package, publishes its Stripe prices, and either changes the existing subscription directly or continues to embedded Checkout for a first subscription. A private package can only be viewed or purchased by its owning tenant. Administrators use the same live calculator, select a tenant, and create, synchronize, and assign the package in one audited action. An active Stripe subscription is changed with prorations and no new Checkout or trial. Customer volume counts unique customers whose review journey begins during the calendar month; follow-up messages for an already-counted customer are included.

The first paid subscription for a business may use a seven-day, card-required trial with ten ReviewOrbit-branded test messages. `trial_used_at` permanently records consumption of that first trial, and deployment migration backfills legacy subscription or trial history. Upgrades and downgrades use the saved Stripe payment method with prorations; they do not open card collection or receive a new trial. Reactivations, later custom packages, and administrator-assigned packages also never receive another trial. Live manual and automated customer sends remain locked until activation, and the API blocks the eleventh test. This cap limits provider exposure while allowing number rental and A2P registration for the customer's live sender to be delayed until conversion.

The administrator-only custom-package calculator accepts customer volume, exact monthly message volume (or messages per customer), locations, SMS segment length, MMS mix, and support level. Its public-price anchors are deterministic: 100 customers and one location reproduces Launch at $49; 300/one reproduces Momentum at $99; 400/two reproduces Expansion at $139. A 65% estimated gross-margin floor raises unusually message-heavy custom quotes. Provider costs, Stripe fees, and the margin model remain internal estimates and are never presented as customer overage charges.

The Stripe webhook is authoritative for subscription status, current plan, billing interval, trial dates, renewal dates, cancellation state, and invoices. `trialing`, `active`, and `past_due` subscriptions retain plan entitlements; payment recovery policy remains configured in Stripe. Every business also has a local plan association so manually assigned and test plans use the correct entitlements before paid checkout.

An administrator may open Checkout or Stripe's hosted payment-method flow only from an active, time-limited support session. The support reason, acting administrator, and billing action are audited. Payment details must be customer-authorized and are entered directly into Stripe; ReviewOrbit never receives or stores the card number.
