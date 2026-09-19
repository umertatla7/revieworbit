<?php

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Models\Business;
use App\Domain\Tenancy\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'subscription_plan_id', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id', 'status', 'billing_interval', 'trial_started_at', 'trial_ends_at', 'trial_used_at', 'current_period_starts_at', 'current_period_ends_at', 'cancel_at_period_end', 'cancelled_at', 'ended_at'])]
class BusinessSubscription extends Model
{
    use HasUlids;

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    protected function casts(): array
    {
        return [
            'trial_started_at' => 'datetime', 'trial_ends_at' => 'datetime', 'trial_used_at' => 'datetime',
            'current_period_starts_at' => 'datetime', 'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime', 'ended_at' => 'datetime', 'cancel_at_period_end' => 'boolean',
        ];
    }
}
