<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Automations\Models\AutomationRule;
use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;

class PlanEntitlements
{
    public function for(Business $business): array
    {
        $plan = SubscriptionPlan::where('code', $business->plan_code)->where('status', 'active')->first()
            ?? SubscriptionPlan::where('code', 'basic')->firstOrFail();
        $locations = $business->locations()->count();
        $templates = MessageTemplate::where('business_id', $business->id)->where('status', '!=', 'archived')->count();
        $automations = AutomationRule::where('business_id', $business->id)->where('status', '!=', 'disabled')->count();
        $media = MediaTemplate::where('business_id', $business->id)->where('status', '!=', 'archived')->count();
        $credits = MessageDelivery::where('business_id', $business->id)->where('created_at', '>=', now()->startOfMonth())->sum('billable_credits');

        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'location_limit' => $plan->location_limit,
            'locations_used' => $locations,
            'locations_remaining' => max(0, $plan->location_limit - $locations),
            'can_add_location' => $locations < $plan->location_limit,
            'template_limit' => $plan->template_limit,
            'templates_used' => $templates,
            'can_add_template' => $templates < $plan->template_limit,
            'automation_limit' => $plan->automation_limit,
            'automations_used' => $automations,
            'can_add_automation' => $automations < $plan->automation_limit,
            'automation_step_limit' => $plan->automation_step_limit,
            'media_template_limit' => $plan->media_template_limit,
            'media_templates_used' => $media,
            'can_add_media_template' => $media < $plan->media_template_limit,
            'included_message_credits' => $plan->included_message_credits,
            'message_credits_used' => (int) $credits,
            'message_credits_remaining' => max(0, $plan->included_message_credits - $credits),
            'allow_overage' => $plan->allow_overage,
            'review_providers' => $plan->review_providers,
            'available_review_providers' => collect(['google', 'trustpilot', 'facebook', 'yelp', 'other'])->map(fn (string $provider): array => [
                'provider' => $provider,
                'included' => in_array($provider, $plan->review_providers, true),
            ])->values(),
        ];
    }

    public function plan(Business $business): SubscriptionPlan
    {
        return SubscriptionPlan::where('code', $business->plan_code)->where('status', 'active')->first()
            ?? SubscriptionPlan::where('code', 'basic')->firstOrFail();
    }
}
