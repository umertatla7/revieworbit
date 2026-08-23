<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('last_visit_at')->nullable()->after('source')->index();
        });
        Schema::create('customer_external_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_customer_id');
            $table->timestamps();
            $table->unique(['business_id', 'provider', 'external_customer_id'], 'customer_external_identity_unique');
            $table->index(['business_id', 'customer_id']);
        });

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->string('channel')->default('sms')->after('name');
        });
        Schema::create('media_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('disk');
            $table->string('background_image_path');
            $table->json('text_configuration');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });
        Schema::create('generated_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_template_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path')->nullable();
            $table->string('mime_type')->default('image/png');
            $table->string('status')->default('pending');
            $table->text('failure_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('external_visit_id')->nullable();
            $table->string('external_payment_id')->nullable();
            $table->string('external_order_id')->nullable();
            $table->string('external_booking_id')->nullable();
            $table->string('type');
            $table->string('status')->default('completed');
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('completed_at');
            $table->json('raw_metadata')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'source', 'external_visit_id'], 'visits_external_identity_unique');
            $table->index(['business_id', 'location_id', 'completed_at']);
            $table->index(['business_id', 'customer_id', 'completed_at']);
        });
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('message_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('trigger_type')->default('visit.completed');
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->unsignedSmallInteger('frequency_limit_days')->default(30);
            $table->boolean('cancel_follow_up_after_click')->default(true);
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'status', 'trigger_type']);
        });
        Schema::create('automation_follow_ups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_template_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->unsignedInteger('delay_minutes');
            $table->boolean('cancel_after_click')->default(true);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['automation_rule_id', 'sequence_number']);
        });
        Schema::create('automation_dispatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_number')->default(0);
            $table->string('decision');
            $table->string('reason_code')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->json('decision_context')->nullable();
            $table->timestamps();
            $table->unique(['visit_id', 'automation_rule_id', 'sequence_number']);
            $table->index(['business_id', 'decision', 'scheduled_for']);
        });

        Schema::create('integration_api_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 16)->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('hmac_secret_encrypted');
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'revoked_at']);
        });
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('operation');
            $table->char('key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['business_id', 'operation', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('integration_api_keys');
        Schema::dropIfExists('automation_dispatches');
        Schema::dropIfExists('automation_follow_ups');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('generated_media');
        Schema::dropIfExists('media_templates');
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropColumn('channel'));
        Schema::dropIfExists('customer_external_identities');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('last_visit_at'));
    }
};
