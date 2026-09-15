<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('subscription_plans')->pluck('id', 'code');

        DB::table('businesses')->orderBy('id')->each(function (object $business) use ($plans): void {
            $planId = $plans->get($business->plan_code);
            if (! $planId) {
                return;
            }

            $subscription = DB::table('business_subscriptions')->where('business_id', $business->id)->first();
            if (! $subscription) {
                DB::table('business_subscriptions')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->id,
                    'subscription_plan_id' => $planId,
                    'status' => 'none',
                    'billing_interval' => 'month',
                    'cancel_at_period_end' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            if (! $subscription->stripe_subscription_id) {
                DB::table('business_subscriptions')->where('business_id', $business->id)->update([
                    'subscription_plan_id' => $planId,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Plan associations are valid business data and are intentionally retained.
    }
};
