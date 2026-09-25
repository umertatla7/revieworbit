<?php

namespace App\Domain\Billing\Services;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;

class CustomPlanManager
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /** @param array<string, mixed> $quote */
    public function configure(Business $business, array $quote, int $trialDays): SubscriptionPlan
    {
        $allowances = $quote['recommended_allowances'];
        $plan = SubscriptionPlan::updateOrCreate(
            ['business_id' => $business->id],
            [
                'code' => 'custom-'.$business->id,
                'name' => 'Custom plan',
                'description' => 'A private package configured for your business.',
                'monthly_price_minor' => $quote['monthly_price_minor'],
                'annual_price_minor' => $quote['annual_price_minor'],
                'annual_discount_months' => $quote['annual_discount_months'] ?? 2,
                'currency' => $quote['currency'],
                'trial_days' => $trialDays,
                'trial_message_limit' => $trialDays > 0 ? 10 : 0,
                'badge' => 'Configured for you',
                'cta_label' => 'Activate custom plan',
                'is_featured' => false,
                'is_self_serve' => false,
                'is_public' => false,
                'sort_order' => 900,
                'features' => ['Private capacity and allowances', 'Follow-up reminders included'],
                'location_limit' => $quote['usage']['locations'],
                'template_limit' => $allowances['template_limit'],
                'automation_limit' => $allowances['automation_limit'],
                'automation_step_limit' => $allowances['automation_step_limit'],
                'media_template_limit' => $allowances['media_template_limit'],
                'review_destination_limit' => $allowances['review_destination_limit'],
                'monthly_customer_limit' => $quote['usage']['monthly_customers'],
                'included_message_credits' => 0,
                'overage_price_minor' => 0,
                'allow_overage' => false,
                'sms_credit_units' => 1,
                'mms_credit_units' => 1,
                'whatsapp_credit_units' => 1,
                'estimated_sms_provider_cost_minor' => 0,
                'estimated_mms_provider_cost_minor' => 0,
                'estimated_whatsapp_provider_cost_minor' => 0,
                'review_providers' => ['google'],
                'status' => 'active',
            ],
        );

        return $this->stripe->syncPlan($plan);
    }
}
