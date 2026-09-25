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
            $table->unsignedTinyInteger('annual_discount_months')->default(2)->after('annual_price_minor');
        });

        DB::table('subscription_plans')->where('monthly_price_minor', '>', 0)->update([
            'annual_discount_months' => 2,
            'annual_price_minor' => DB::raw('monthly_price_minor * 10'),
        ]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('annual_discount_months');
        });
    }
};
