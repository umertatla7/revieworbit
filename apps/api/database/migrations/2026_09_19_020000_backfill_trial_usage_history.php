<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('business_subscriptions')
            ->whereNull('trial_used_at')
            ->where(function ($query): void {
                $query->whereNotNull('trial_started_at')->orWhereNotNull('stripe_subscription_id');
            })
            ->orderBy('id')
            ->each(function (object $subscription): void {
                DB::table('business_subscriptions')->where('id', $subscription->id)->update([
                    'trial_used_at' => $subscription->trial_started_at ?? $subscription->created_at ?? now(),
                ]);
            });
    }

    public function down(): void {}
};
