<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'status')) {
            return;
        }

        $dependentTables = ['business_users', 'platform_user_roles', 'business_invitations', 'audit_logs'];
        $hasDependentRecords = collect($dependentTables)
            ->filter(fn (string $table): bool => Schema::hasTable($table))
            ->contains(fn (string $table): bool => DB::table($table)->exists());

        if (DB::table('users')->exists() || $hasDependentRecords) {
            throw new RuntimeException('Legacy user schema contains records. Export and migrate identities before applying this repair.');
        }

        Schema::disableForeignKeyConstraints();
        foreach (['audit_logs', 'business_invitations', 'platform_user_roles', 'business_users', 'sessions', 'password_reset_tokens', 'personal_access_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status')->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
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
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // This forward-only repair is intentionally irreversible.
    }
};
