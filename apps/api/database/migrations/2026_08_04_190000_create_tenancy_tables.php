<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->string('default_timezone')->default('UTC');
            $table->char('default_country', 2)->default('US');
            $table->string('logo_path')->nullable();
            $table->json('brand_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('business_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index(['business_id', 'status', 'role']);
        });

        Schema::create('platform_user_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('external_reference')->nullable();
            $table->json('address')->nullable();
            $table->string('timezone');
            $table->string('phone', 32)->nullable();
            $table->text('google_review_url')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['business_id', 'external_reference']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('business_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'expires_at']);
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_invitations');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('platform_user_roles');
        Schema::dropIfExists('business_users');
        Schema::dropIfExists('businesses');
    }
};
