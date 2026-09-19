<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Billing\Models\BusinessSubscription;
use App\Domain\Messaging\Models\MessageDelivery;
use App\Domain\Messaging\Models\TestMessageDelivery;
use App\Domain\Tenancy\Models\Business;
use Illuminate\Validation\ValidationException;

class TrialMessageLimiter
{
    public function assertMaySend(Business $business, bool $isTest = false): void
    {
        $subscription = BusinessSubscription::with('plan')
            ->where('business_id', $business->id)
            ->first();

        if (! $subscription || $subscription->status !== 'trialing') {
            return;
        }

        if (! $isTest) {
            throw ValidationException::withMessages([
                'trial' => ['The free trial includes test messages only. Activate billing before sending live or automated customer messages.'],
            ]);
        }

        $limit = (int) ($subscription->plan?->trial_message_limit ?? 0);
        if ($limit < 1) {
            throw ValidationException::withMessages([
                'trial' => ['Live messaging becomes available when the paid subscription starts.'],
            ]);
        }

        $from = $subscription->trial_started_at ?? $subscription->created_at;
        $used = MessageDelivery::where('business_id', $business->id)->where('created_at', '>=', $from)->count()
            + TestMessageDelivery::where('business_id', $business->id)->where('created_at', '>=', $from)->count();

        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'trial' => ["Your {$limit}-message trial allowance has been used. Activate the subscription to continue sending."],
            ]);
        }
    }
}
