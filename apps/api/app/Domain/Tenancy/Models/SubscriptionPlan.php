<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['business_id', 'code', 'name', 'description', 'monthly_price_minor', 'annual_price_minor', 'annual_discount_months', 'currency', 'trial_days', 'trial_message_limit', 'badge', 'cta_label', 'is_featured', 'is_self_serve', 'is_public', 'sort_order', 'features', 'stripe_product_id', 'stripe_monthly_price_id', 'stripe_annual_price_id', 'location_limit', 'template_limit', 'automation_limit', 'automation_step_limit', 'media_template_limit', 'review_destination_limit', 'included_message_credits', 'monthly_customer_limit', 'overage_price_minor', 'sms_credit_units', 'mms_credit_units', 'whatsapp_credit_units', 'estimated_sms_provider_cost_minor', 'estimated_mms_provider_cost_minor', 'estimated_whatsapp_provider_cost_minor', 'review_providers', 'allow_overage', 'status'])]
class SubscriptionPlan extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'review_providers' => 'array',
            'features' => 'array',
            'allow_overage' => 'boolean',
            'is_featured' => 'boolean',
            'is_self_serve' => 'boolean',
            'is_public' => 'boolean',
            'monthly_price_minor' => 'integer',
            'annual_price_minor' => 'integer',
            'annual_discount_months' => 'integer',
            'trial_message_limit' => 'integer',
            'included_message_credits' => 'integer',
            'monthly_customer_limit' => 'integer',
            'overage_price_minor' => 'integer',
        ];
    }
}
