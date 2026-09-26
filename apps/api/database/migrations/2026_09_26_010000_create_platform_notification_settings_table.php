<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('registration_email')->default('zee@buckeyerank.com');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_settings');
    }
};
