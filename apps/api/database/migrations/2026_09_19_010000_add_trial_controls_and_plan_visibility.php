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
            $table->foreignUlid('business_id')->nullable()->unique()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('trial_message_limit')->default(10)->after('trial_days');
            $table->boolean('is_public')->default(true)->after('is_self_serve');
        });

        Schema::table('business_subscriptions', function (Blueprint $table): void {
            $table->timestampTz('trial_used_at')->nullable()->after('trial_ends_at');
        });

        DB::table('subscription_plans')
            ->whereIn('code', ['launch', 'momentum', 'expansion'])
            ->update([
                'trial_days' => 7,
                'trial_message_limit' => 10,
                'is_public' => true,
                'updated_at' => now(),
            ]);

        DB::table('subscription_plans')
            ->where('code', 'enterprise')
            ->update([
                'description' => null,
                'features' => json_encode([]),
                'trial_days' => 0,
                'trial_message_limit' => 0,
                'is_public' => true,
                'cta_label' => 'Book a Call',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('business_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('trial_used_at');
        });

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['trial_message_limit', 'is_public']);
        });
    }
};
