<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('monthly_price_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('location_limit')->default(1);
            $table->unsignedSmallInteger('template_limit')->default(2);
            $table->unsignedSmallInteger('automation_limit')->default(1);
            $table->unsignedSmallInteger('automation_step_limit')->default(1);
            $table->unsignedSmallInteger('media_template_limit')->default(1);
            $table->unsignedInteger('included_message_credits')->default(100);
            $table->unsignedInteger('overage_price_minor')->default(0);
            $table->unsignedSmallInteger('sms_credit_units')->default(1);
            $table->unsignedSmallInteger('mms_credit_units')->default(3);
            $table->unsignedSmallInteger('whatsapp_credit_units')->default(1);
            $table->unsignedInteger('estimated_sms_provider_cost_minor')->default(0);
            $table->unsignedInteger('estimated_mms_provider_cost_minor')->default(0);
            $table->unsignedInteger('estimated_whatsapp_provider_cost_minor')->default(0);
            $table->json('review_providers');
            $table->boolean('allow_overage')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $now = now();
        DB::table('subscription_plans')->insert([
            ['id' => (string) str()->ulid(), 'code' => 'basic', 'name' => 'Basic', 'monthly_price_minor' => 4900, 'currency' => 'USD', 'location_limit' => 1, 'template_limit' => 3, 'automation_limit' => 2, 'automation_step_limit' => 2, 'media_template_limit' => 1, 'included_message_credits' => 100, 'overage_price_minor' => 15, 'sms_credit_units' => 1, 'mms_credit_units' => 3, 'whatsapp_credit_units' => 1, 'review_providers' => json_encode(['google']), 'allow_overage' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) str()->ulid(), 'code' => 'growth', 'name' => 'Growth', 'monthly_price_minor' => 9900, 'currency' => 'USD', 'location_limit' => 5, 'template_limit' => 10, 'automation_limit' => 8, 'automation_step_limit' => 4, 'media_template_limit' => 5, 'included_message_credits' => 1000, 'overage_price_minor' => 12, 'sms_credit_units' => 1, 'mms_credit_units' => 3, 'whatsapp_credit_units' => 1, 'review_providers' => json_encode(['google', 'trustpilot', 'facebook']), 'allow_overage' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) str()->ulid(), 'code' => 'pro', 'name' => 'Pro', 'monthly_price_minor' => 19900, 'currency' => 'USD', 'location_limit' => 25, 'template_limit' => 50, 'automation_limit' => 25, 'automation_step_limit' => 4, 'media_template_limit' => 25, 'included_message_credits' => 5000, 'overage_price_minor' => 9, 'sms_credit_units' => 1, 'mms_credit_units' => 3, 'whatsapp_credit_units' => 1, 'review_providers' => json_encode(['google', 'trustpilot', 'facebook', 'yelp', 'other']), 'allow_overage' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->foreignUlid('location_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            $table->foreignUlid('review_destination_id')->nullable()->after('location_id')->constrained('location_review_destinations')->nullOnDelete();
            $table->index(['business_id', 'location_id']);
        });

        Schema::table('review_links', function (Blueprint $table): void {
            $table->foreignUlid('review_destination_id')->nullable()->after('location_id')->constrained('location_review_destinations')->nullOnDelete();
        });

        Schema::table('message_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['automation_dispatch_id']);
            $table->foreignUlid('location_id')->nullable()->after('business_id')->constrained()->nullOnDelete();
            $table->foreignUlid('visit_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
            $table->foreignUlid('requested_by_user_id')->nullable()->after('visit_id')->constrained('users')->nullOnDelete();
            $table->string('delivery_type')->default('automation')->after('requested_by_user_id');
            $table->text('body_snapshot')->nullable()->after('channel');
            $table->unsignedSmallInteger('billable_credits')->default(0)->after('body_snapshot');
            $table->unsignedInteger('estimated_cost_minor')->default(0)->after('billable_credits');
            $table->integer('provider_cost_minor')->nullable()->after('estimated_cost_minor');
            $table->char('provider_currency', 3)->nullable()->after('provider_cost_minor');
            $table->foreign('automation_dispatch_id')->references('id')->on('automation_dispatches')->cascadeOnDelete();
            $table->index(['business_id', 'delivery_type', 'created_at']);
        });
        Schema::table('message_deliveries', function (Blueprint $table): void {
            $table->ulid('automation_dispatch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement('DELETE FROM message_deliveries WHERE automation_dispatch_id IS NULL');
        Schema::table('message_deliveries', function (Blueprint $table): void {
            $table->ulid('automation_dispatch_id')->nullable(false)->change();
        });
        Schema::table('message_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'delivery_type', 'created_at']);
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('visit_id');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['delivery_type', 'body_snapshot', 'billable_credits', 'estimated_cost_minor', 'provider_cost_minor', 'provider_currency']);
        });
        Schema::table('review_links', fn (Blueprint $table) => $table->dropConstrainedForeignId('review_destination_id'));
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'location_id']);
            $table->dropConstrainedForeignId('review_destination_id');
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::dropIfExists('subscription_plans');
    }
};
