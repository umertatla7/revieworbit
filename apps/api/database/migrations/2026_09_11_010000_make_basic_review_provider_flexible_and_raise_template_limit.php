<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->where('code', 'basic')->update([
            'template_limit' => 5,
            'review_providers' => json_encode(['google', 'trustpilot', 'facebook', 'yelp', 'other']),
            'updated_at' => now(),
        ]);

        DB::table('message_templates')->where('channel', 'whatsapp')->update([
            'status' => 'archived',
            'updated_at' => now(),
        ]);
        DB::table('messaging_configurations')->update([
            'whatsapp_enabled' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('code', 'basic')->update([
            'template_limit' => 1,
            'review_providers' => json_encode(['google']),
            'updated_at' => now(),
        ]);
    }
};
