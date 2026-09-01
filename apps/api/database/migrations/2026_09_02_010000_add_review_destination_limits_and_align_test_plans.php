<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('review_destination_limit')->default(1)->after('media_template_limit');
        });

        $limits = [
            'basic' => ['limit' => 1, 'providers' => ['google']],
            'growth' => ['limit' => 5, 'providers' => ['google', 'trustpilot', 'facebook', 'yelp', 'other']],
            'pro' => ['limit' => 15, 'providers' => ['google', 'trustpilot', 'facebook', 'yelp', 'other']],
        ];

        foreach ($limits as $code => $configuration) {
            DB::table('subscription_plans')->where('code', $code)->update([
                'location_limit' => $configuration['limit'],
                'template_limit' => $configuration['limit'],
                'media_template_limit' => $configuration['limit'],
                'review_destination_limit' => $configuration['limit'],
                'review_providers' => json_encode($configuration['providers']),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('review_destination_limit');
        });
    }
};
