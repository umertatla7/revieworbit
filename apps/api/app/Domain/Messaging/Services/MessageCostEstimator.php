<?php

namespace App\Domain\Messaging\Services;

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

    public function assertAvailable(Business $business, int $credits): void
    {
        $limits = $this->entitlements->for($business);
        abort_if(! $limits['allow_overage'] && $limits['message_credits_remaining'] < $credits, 422, 'This business has no message credits remaining.');
    }
}
