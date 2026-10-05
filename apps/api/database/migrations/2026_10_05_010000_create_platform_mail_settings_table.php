<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 20);
            $table->string('username');
            $table->text('password');
            $table->string('from_address');
            $table->string('from_name', 100);
            $table->boolean('enabled')->default(true);
            $table->string('status', 30)->default('draft');
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};
