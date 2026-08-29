<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_twilio_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('account_sid', 34)->unique();
            $table->text('auth_token');
            $table->string('mode')->default('trial');
            $table->string('status')->default('draft');
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('test_message_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_template_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_message_deliveries');
        Schema::dropIfExists('platform_twilio_settings');
    }
};
