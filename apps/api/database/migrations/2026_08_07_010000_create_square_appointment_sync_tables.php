<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_integrations', function (Blueprint $table): void {
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->json('sync_summary')->nullable();
        });

        Schema::create('square_oauth_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('integration_id')->constrained('pos_integrations')->cascadeOnDelete();
            $table->foreignUlid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('state_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'expires_at', 'consumed_at']);
        });

        Schema::create('square_appointments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('integration_id')->constrained('pos_integrations')->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_booking_id');
            $table->string('external_location_id')->nullable();
            $table->string('external_customer_id')->nullable();
            $table->string('status');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->json('service_summary')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['business_id', 'integration_id', 'external_booking_id'], 'square_appointments_external_unique');
            $table->index(['business_id', 'starts_at', 'status']);
            $table->index(['business_id', 'customer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_appointments');
        Schema::dropIfExists('square_oauth_states');

        Schema::table('pos_integrations', function (Blueprint $table): void {
            $table->dropColumn([
                'access_token_encrypted',
                'refresh_token_encrypted',
                'token_expires_at',
                'last_synced_at',
                'sync_error',
                'sync_summary',
            ]);
        });
    }
};
