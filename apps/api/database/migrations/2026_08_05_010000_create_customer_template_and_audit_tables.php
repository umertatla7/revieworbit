<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_e164', 32)->nullable();
            $table->char('phone_hash', 64)->nullable();
            $table->string('status')->default('active');
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['business_id', 'phone_hash']);
            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('customer_consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('sms');
            $table->string('status');
            $table->string('source');
            $table->timestamp('recorded_at');
            $table->timestamp('expires_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'channel', 'recorded_at']);
        });

        Schema::create('suppression_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('sms');
            $table->string('reason');
            $table->timestamp('suppressed_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'channel', 'released_at']);
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->boolean('include_media')->default(false);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status')->default('ready');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->string('target_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('request_id', 26)->nullable();
            $table->timestamp('created_at');

            $table->index(['business_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('suppression_entries');
        Schema::dropIfExists('customer_consents');
        Schema::dropIfExists('customers');
    }
};
