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
            $table->text('description')->nullable();
            $table->unsignedInteger('annual_price_minor')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->string('badge', 40)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('features')->nullable();
            $table->string('stripe_product_id')->nullable()->unique();
            $table->string('stripe_monthly_price_id')->nullable()->unique();
            $table->string('stripe_annual_price_id')->nullable()->unique();
        });

        DB::table('subscription_plans')->orderBy('monthly_price_minor')->get()->each(function ($plan, int $index): void {
            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'annual_price_minor' => (int) $plan->monthly_price_minor * 10,
                'sort_order' => $index + 1,
                'is_featured' => $plan->code === 'growth',
                'badge' => $plan->code === 'growth' ? 'Most popular' : null,
                'description' => match ($plan->code) {
                    'basic' => 'Essential review follow-up tools for a single-location business.',
                    'growth' => 'More locations, automation, and message capacity for growing teams.',
                    'pro' => 'High-volume controls and generous allowances for established operators.',
                    default => null,
                },
                'features' => json_encode([]),
            ]);
        });

        Schema::create('platform_stripe_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('publishable_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('mode')->default('test');
            $table->string('status')->default('draft');
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->string('portal_configuration_id')->nullable();
            $table->ulid('updated_by_user_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('business_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('business_id')->unique();
            $table->ulid('subscription_plan_id')->nullable();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->string('status')->default('none')->index();
            $table->string('billing_interval')->default('month');
            $table->timestampTz('trial_started_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('subscription_plan_id')->references('id')->on('subscription_plans')->nullOnDelete();
            $table->index(['business_id', 'status']);
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->string('stripe_invoice_id')->unique();
            $table->string('number')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('amount_due_minor')->default(0);
            $table->unsignedInteger('amount_paid_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('hosted_invoice_url', 2048)->nullable();
            $table->string('invoice_pdf_url', 2048)->nullable();
            $table->timestampTz('period_starts_at')->nullable();
            $table->timestampTz('period_ends_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->boolean('livemode')->default(false);
            $table->string('status')->default('received')->index();
            $table->json('payload');
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('business_subscriptions');
        Schema::dropIfExists('platform_stripe_settings');
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn(['description', 'annual_price_minor', 'trial_days', 'badge', 'is_featured', 'sort_order', 'features', 'stripe_product_id', 'stripe_monthly_price_id', 'stripe_annual_price_id']);
        });
    }
};
