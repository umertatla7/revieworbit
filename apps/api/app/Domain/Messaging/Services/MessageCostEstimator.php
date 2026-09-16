<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Templates\Services\TemplateRenderer;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Services\PlanEntitlements;

class MessageCostEstimator
{
    public function __construct(private readonly PlanEntitlements $entitlements, private readonly TemplateRenderer $renderer) {}

    public function estimate(Business $business, string $channel, string $body, bool $hasMedia = false): array
    {
        $plan = $this->entitlements->plan($business);
        $segments = max(1, (int) ($this->renderer->estimate($body)['segments'] ?? 1));
        $units = match (true) {
            $channel === 'whatsapp' => $plan->whatsapp_credit_units,
            $hasMedia => $plan->mms_credit_units,
            default => $plan->sms_credit_units * $segments,
        };
        $estimatedCost = match (true) {
            $channel === 'whatsapp' => $plan->estimated_whatsapp_provider_cost_minor,
            $hasMedia => $plan->estimated_mms_provider_cost_minor,
            default => $plan->estimated_sms_provider_cost_minor * $segments,
        };

        return [
            'billable_credits' => $units,
            'estimated_cost_minor' => $estimatedCost,
            'provider_currency' => $plan->currency,
        ];
    }

    public function assertCustomerAvailable(Business $business, string $customerId): void
    {
        Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
        $limits = $this->entitlements->for($business);
        if ($limits['monthly_customer_limit'] === null) {
            return;
        }
        $alreadyCounted = MessageDelivery::where('business_id', $business->id)
            ->where('customer_id', $customerId)->where('created_at', '>=', now()->startOfMonth())->exists();
        abort_if(! $alreadyCounted && $limits['customers_remaining_this_month'] < 1, 422, 'This plan has reached its monthly customer limit. Upgrade to start review requests for more customers.');
    }
}
