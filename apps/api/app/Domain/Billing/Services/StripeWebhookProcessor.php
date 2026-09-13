<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

class StripeWebhookProcessor
{
    public function process(array $event): void
    {
        $object = data_get($event, 'data.object', []);
        match ($event['type']) {
            'checkout.session.completed' => $this->checkout($object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscription($object),
            'invoice.finalized', 'invoice.paid', 'invoice.payment_failed', 'invoice.voided' => $this->invoice($object),
            default => null,
        };
    }

    private function checkout(array $object): void
    {
        $businessId = data_get($object, 'metadata.business_id') ?: data_get($object, 'client_reference_id');
        if (! $businessId || ! Business::whereKey($businessId)->exists()) {
            return;
        }
        BusinessSubscription::updateOrCreate(['business_id' => $businessId], [
            'stripe_customer_id' => $this->id($object['customer'] ?? null),
            'stripe_subscription_id' => $this->id($object['subscription'] ?? null),
        ]);
    }

    private function subscription(array $object): void
    {
        $businessId = data_get($object, 'metadata.business_id');
        $existing = BusinessSubscription::where('stripe_subscription_id', $object['id'])->first();
        if (! $businessId) {
            $businessId = $existing?->business_id;
        }
        if (! $businessId || ! ($business = Business::find($businessId))) {
            return;
        }
        $priceId = data_get($object, 'items.data.0.price.id');
        $plan = SubscriptionPlan::where('stripe_monthly_price_id', $priceId)->orWhere('stripe_annual_price_id', $priceId)->first();
        $status = $object['status'] ?? 'unknown';
        $values = [
            'subscription_plan_id' => $plan?->id,
            'stripe_customer_id' => $this->id($object['customer'] ?? null),
            'stripe_subscription_id' => $object['id'], 'stripe_price_id' => $priceId,
            'status' => $status, 'billing_interval' => data_get($object, 'items.data.0.price.recurring.interval', 'month'),
            'trial_started_at' => $this->date($object['trial_start'] ?? null), 'trial_ends_at' => $this->date($object['trial_end'] ?? null),
            'current_period_starts_at' => $this->date($object['current_period_start'] ?? data_get($object, 'items.data.0.current_period_start')),
            'current_period_ends_at' => $this->date($object['current_period_end'] ?? data_get($object, 'items.data.0.current_period_end')),
            'cancel_at_period_end' => (bool) ($object['cancel_at_period_end'] ?? false),
            'cancelled_at' => $this->date($object['canceled_at'] ?? null), 'ended_at' => $this->date($object['ended_at'] ?? null),
        ];
        BusinessSubscription::updateOrCreate(['business_id' => $business->id], $values);
        if ($plan && in_array($status, ['trialing', 'active', 'past_due'], true)) {
            $business->update(['plan_code' => $plan->code]);
        }
    }

    private function invoice(array $object): void
    {
        $customerId = $this->id($object['customer'] ?? null);
        $subscription = BusinessSubscription::where('stripe_customer_id', $customerId)->first();
        if (! $subscription) {
            return;
        }
        BillingInvoice::updateOrCreate(['stripe_invoice_id' => $object['id']], [
            'business_id' => $subscription->business_id, 'number' => $object['number'] ?? null,
            'status' => $object['status'] ?? null, 'amount_due_minor' => $object['amount_due'] ?? 0,
            'amount_paid_minor' => $object['amount_paid'] ?? 0, 'currency' => strtoupper($object['currency'] ?? 'USD'),
            'hosted_invoice_url' => $object['hosted_invoice_url'] ?? null, 'invoice_pdf_url' => $object['invoice_pdf'] ?? null,
            'period_starts_at' => $this->date($object['period_start'] ?? null), 'period_ends_at' => $this->date($object['period_end'] ?? null),
            'paid_at' => $this->date(data_get($object, 'status_transitions.paid_at')),
        ]);
    }

    private function date(mixed $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestampUTC((int) $timestamp) : null;
    }

    private function id(mixed $value): ?string
    {
        return is_array($value) ? ($value['id'] ?? null) : $value;
    }
}
