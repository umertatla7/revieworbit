# Stripe billing

ReviewOrbit uses one platform Stripe account. Each tenant maps to a Stripe Customer and at most one active Stripe Subscription. Card details are collected and managed only by Stripe Checkout and the Stripe-hosted Customer Portal; ReviewOrbit never receives or stores card data.

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

New subscribers use Stripe Checkout. A plan's configured trial is offered only when the tenant has never started a Stripe trial or subscription. Existing subscribers use a Customer Portal deep link to confirm a plan change. The customer billing screen has Overview, Plans, Payment methods, and Invoices tabs. Payment methods are masked, invoices are retrieved from Stripe, and the dedicated **Add/Change payment method** action opens Stripe's `payment_method_update` portal flow. General portal access supports subscription management, payment-method changes, invoices, cancellation, and other options enabled in the Stripe Portal configuration. Stripe recommends synchronizing access from subscription webhooks, which ReviewOrbit does: <https://docs.stripe.com/customer-management/integrate-customer-portal>. Stripe documents the payment-method deep-link flow here: <https://docs.stripe.com/customer-management/portal-deep-links>.

The Stripe webhook is authoritative for subscription status, current plan, billing interval, trial dates, renewal dates, cancellation state, and invoices. `trialing`, `active`, and `past_due` subscriptions retain plan entitlements; payment recovery policy remains configured in Stripe. Platform administrators can see billing status but cannot open a customer's private Stripe Portal session through support mode.
