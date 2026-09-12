<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_toast_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('environment')->default('sandbox');
            $table->string('api_base_url')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('partner_webhook_secret')->nullable();
            $table->text('orders_webhook_secret')->nullable();
            $table->string('marketplace_url', 2048)->nullable();
            $table->string('status')->default('draft');
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('environment');
        });

        Schema::create('toast_connection_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('integration_id')->constrained('pos_integrations')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('connection_code_hash', 64)->unique();
            $table->text('connection_code_encrypted');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'expires_at']);
            $table->index(['business_id', 'location_id', 'status']);
        });

        Schema::create('toast_restaurant_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('integration_id')->constrained('pos_integrations')->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->string('environment')->default('sandbox');
            $table->uuid('restaurant_guid');
            $table->uuid('management_group_guid')->nullable();
            $table->string('restaurant_name')->nullable();
            $table->string('location_name')->nullable();
            $table->string('external_group_ref')->nullable();
            $table->string('external_restaurant_ref')->nullable();
            $table->string('status')->default('connected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->json('sync_summary')->nullable();
            $table->timestamps();

            $table->unique(['environment', 'restaurant_guid']);
            $table->unique(['business_id', 'location_id', 'environment'], 'toast_restaurant_location_unique');
            $table->index(['business_id', 'status']);
        });

        Schema::create('integration_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('category');
            $table->string('event_type');
            $table->string('external_event_id');
            $table->char('payload_fingerprint', 64);
            $table->jsonb('payload');
            $table->string('status')->default('received');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->timestamp('verified_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index(['provider', 'status', 'created_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_events');
        Schema::dropIfExists('toast_restaurant_connections');
        Schema::dropIfExists('toast_connection_requests');
        Schema::dropIfExists('platform_toast_settings');
    }
};
