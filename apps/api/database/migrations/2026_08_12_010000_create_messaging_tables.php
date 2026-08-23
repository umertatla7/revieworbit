<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_configurations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('provider')->default('twilio');
            $table->string('status')->default('draft');
            $table->string('twilio_subaccount_sid')->nullable();
            $table->string('twilio_messaging_service_sid')->nullable();
            $table->string('sms_sender', 32)->nullable();
            $table->string('whatsapp_sender', 32)->nullable();
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'provider']);
            $table->index('twilio_messaging_service_sid');
        });

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->string('provider_template_sid')->nullable();
        });

        Schema::create('review_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('visit_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('destination_url');
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['business_id', 'visit_id']);
        });

        Schema::create('message_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('automation_dispatch_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_template_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('review_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('twilio');
            $table->string('channel');
            $table->char('to_hash', 64);
            $table->string('to_last_four', 4);
            $table->string('provider_message_sid')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->string('provider_error_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['business_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('review_links');
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('provider_template_sid'));
        Schema::dropIfExists('messaging_configurations');
    }
};
