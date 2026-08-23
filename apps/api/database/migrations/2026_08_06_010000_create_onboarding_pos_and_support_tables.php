<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('onboarding_status')->default('in_progress')->index();
            $table->unsignedTinyInteger('onboarding_step')->default(1);
            $table->string('operation_mode')->default('manual');
            $table->json('messaging_preferences')->nullable();
            $table->timestamp('consent_confirmed_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
        });

        Schema::create('pos_integrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('environment')->default('sandbox');
            $table->string('status')->default('setup_required');
            $table->string('external_merchant_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'provider', 'name']);
            $table->index(['business_id', 'status', 'provider']);
        });

        Schema::create('admin_support_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'expires_at', 'ended_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_support_sessions');
        Schema::dropIfExists('pos_integrations');

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_status', 'onboarding_step', 'operation_mode', 'messaging_preferences',
                'consent_confirmed_at', 'onboarding_completed_at',
            ]);
        });
    }
};
