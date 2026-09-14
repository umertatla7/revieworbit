<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->update([
            'overage_price_minor' => 0,
            'allow_overage' => false,
            'sms_credit_units' => 1,
            'mms_credit_units' => 1,
            'whatsapp_credit_units' => 1,
            'estimated_sms_provider_cost_minor' => 0,
            'estimated_mms_provider_cost_minor' => 0,
            'estimated_whatsapp_provider_cost_minor' => 0,
        ]);
    }

    public function down(): void
    {
        // Restoring obsolete per-message charges would risk billing customers.
    }
};
