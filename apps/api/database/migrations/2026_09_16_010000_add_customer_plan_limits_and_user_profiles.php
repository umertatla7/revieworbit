<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedInteger('monthly_customer_limit')->nullable()->after('included_message_credits');
            $table->boolean('is_self_serve')->default(true)->after('is_featured');
            $table->string('cta_label', 60)->default('Start Free Trial')->after('badge');
        });
        Schema::table('users', fn (Blueprint $table) => $table->string('avatar_path')->nullable()->after('phone'));

        $plans = [
            'basic' => ['code' => 'launch', 'name' => 'Launch', 'price' => 4900, 'customers' => 100, 'locations' => 1, 'badge' => null, 'featured' => false, 'description' => 'For single-location businesses starting to build their Google reviews.'],
            'growth' => ['code' => 'momentum', 'name' => 'Momentum', 'price' => 9900, 'customers' => 300, 'locations' => 1, 'badge' => 'Most popular', 'featured' => true, 'description' => 'For busy single-location businesses serving customers every day.'],
            'pro' => ['code' => 'expansion', 'name' => 'Expansion', 'price' => 13900, 'customers' => 400, 'locations' => 2, 'badge' => 'Best for 2 locations', 'featured' => false, 'description' => 'For businesses running two locations that need reviews coming in at both.'],
        ];
        foreach ($plans as $oldCode => $plan) {
            $id = DB::table('subscription_plans')->where('code', $oldCode)->value('id');
            if (! $id) {
                continue;
            }
            DB::table('businesses')->where('plan_code', $oldCode)->update(['plan_code' => $plan['code']]);
            DB::table('subscription_plans')->where('id', $id)->update([
                'code' => $plan['code'], 'name' => $plan['name'], 'description' => $plan['description'],
                'monthly_price_minor' => $plan['price'], 'annual_price_minor' => $plan['price'] * 10,
                'monthly_customer_limit' => $plan['customers'], 'location_limit' => $plan['locations'],
                'review_destination_limit' => $plan['locations'], 'badge' => $plan['badge'],
                'is_featured' => $plan['featured'], 'is_self_serve' => true, 'cta_label' => 'Start Free Trial',
                'allow_overage' => false, 'stripe_monthly_price_id' => null, 'stripe_annual_price_id' => null,
                'review_providers' => json_encode(['google', 'trustpilot', 'facebook', 'yelp', 'other']),
                'features' => json_encode($plan['locations'] > 1
                    ? ['Automated SMS review requests', 'Follow-up reminders included', 'Customizable message templates', 'Contact import (manual + CSV)', 'Review performance dashboard', 'Per-location campaigns', 'Combined multi-location analytics', 'POS and business tool integrations', 'Guided onboarding support']
                    : ['Automated SMS review requests', 'Follow-up reminders included', 'Customizable message templates', 'Contact import (manual + CSV)', 'Review performance dashboard', 'POS and business tool integrations', 'Guided onboarding support']),
                'updated_at' => now(),
            ]);
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('plan_code')->default('launch')->change();
        });

        if (! DB::table('subscription_plans')->where('code', 'enterprise')->exists()) {
            DB::table('subscription_plans')->insert([
                'id' => (string) str()->ulid(), 'code' => 'enterprise', 'name' => 'Enterprise',
                'description' => 'For multi-location brands and high-volume businesses.',
                'monthly_price_minor' => 0, 'annual_price_minor' => 0, 'currency' => 'USD', 'trial_days' => 0,
                'badge' => 'Tailored', 'cta_label' => 'Book a Call', 'is_featured' => false, 'is_self_serve' => false, 'sort_order' => 4,
                'features' => json_encode(['Custom customer volume', 'Volume-based pricing per location', 'Combined multi-location reporting', 'Dedicated account manager', 'Priority support', 'Custom integrations and guided onboarding']),
                'location_limit' => 3, 'template_limit' => 1000, 'automation_limit' => 1000, 'automation_step_limit' => 10,
                'media_template_limit' => 1000, 'review_destination_limit' => 1000, 'included_message_credits' => 0,
                'monthly_customer_limit' => null, 'overage_price_minor' => 0, 'sms_credit_units' => 1, 'mms_credit_units' => 1,
                'whatsapp_credit_units' => 1, 'estimated_sms_provider_cost_minor' => 0, 'estimated_mms_provider_cost_minor' => 0,
                'estimated_whatsapp_provider_cost_minor' => 0, 'review_providers' => json_encode(['google', 'trustpilot', 'facebook', 'yelp', 'other']),
                'allow_overage' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('code', 'enterprise')->delete();
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_path'));
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn(['monthly_customer_limit', 'is_self_serve', 'cta_label']));
    }
};
