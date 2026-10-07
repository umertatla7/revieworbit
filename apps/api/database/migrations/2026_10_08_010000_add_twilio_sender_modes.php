<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_twilio_settings', function (Blueprint $table): void {
            $table->string('shared_messaging_service_sid', 34)->nullable()->after('auth_token');
            $table->string('shared_sms_sender', 32)->nullable()->after('shared_messaging_service_sid');
            $table->boolean('shared_sender_enabled')->default(false)->after('shared_sms_sender');
            $table->timestamp('shared_sender_compliance_confirmed_at')->nullable()->after('shared_sender_enabled');
        });

        Schema::table('messaging_configurations', function (Blueprint $table): void {
            $table->string('sender_mode')->default('platform_shared')->after('provider');
            $table->text('twilio_auth_token')->nullable()->after('twilio_subaccount_sid');
            $table->index(['sender_mode', 'status']);
        });

        // Existing rows were explicitly configured per business and must retain
        // that meaning. Only newly-created workspaces inherit the shared sender.
        DB::table('messaging_configurations')
            ->whereNotNull('twilio_messaging_service_sid')
            ->update(['sender_mode' => 'platform_dedicated']);
    }

    public function down(): void
    {
        Schema::table('messaging_configurations', function (Blueprint $table): void {
            $table->dropIndex(['sender_mode', 'status']);
            $table->dropColumn(['sender_mode', 'twilio_auth_token']);
        });

        Schema::table('platform_twilio_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'shared_messaging_service_sid',
                'shared_sms_sender',
                'shared_sender_enabled',
                'shared_sender_compliance_confirmed_at',
            ]);
        });
    }
};
