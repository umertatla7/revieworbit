<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('plan_code')->default('basic')->after('status')->index();
        });

        Schema::create('location_review_destinations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('url');
            $table->string('status')->default('active');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['location_id', 'provider']);
            $table->index(['business_id', 'provider', 'status']);
        });

        DB::table('locations')->whereNotNull('google_review_url')->orderBy('id')->each(function (object $location): void {
            DB::table('location_review_destinations')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $location->business_id,
                'location_id' => $location->id,
                'provider' => 'google',
                'url' => $location->google_review_url,
                'status' => 'active',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_review_destinations');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('plan_code'));
    }
};
