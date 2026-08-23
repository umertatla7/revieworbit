<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Business;

class PlanEntitlements
{
    private const PLANS = [
        'basic' => ['name' => 'Basic', 'locations' => 1, 'review_providers' => ['google']],
        'growth' => ['name' => 'Growth', 'locations' => 5, 'review_providers' => ['google', 'trustpilot', 'facebook']],
        'pro' => ['name' => 'Pro', 'locations' => 25, 'review_providers' => ['google', 'trustpilot', 'facebook', 'yelp', 'other']],
    ];

    public function for(Business $business): array
    {
        $code = array_key_exists($business->plan_code, self::PLANS) ? $business->plan_code : 'basic';
        $plan = self::PLANS[$code];
        $used = $business->locations()->count();

        return [
            'plan_code' => $code,
            'plan_name' => $plan['name'],
            'location_limit' => $plan['locations'],
            'locations_used' => $used,
            'locations_remaining' => max(0, $plan['locations'] - $used),
            'can_add_location' => $used < $plan['locations'],
            'review_providers' => $plan['review_providers'],
            'available_review_providers' => collect(['google', 'trustpilot', 'facebook', 'yelp', 'other'])->map(fn (string $provider): array => [
                'provider' => $provider,
                'included' => in_array($provider, $plan['review_providers'], true),
            ])->values(),
        ];
    }
}
