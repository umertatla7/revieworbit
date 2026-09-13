<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('review_request_status')->default('eligible')->after('status');
            $table->timestamp('review_confirmed_at')->nullable()->after('review_request_status');
            $table->string('review_confirmation_source')->nullable()->after('review_confirmed_at');
            $table->string('review_confirmation_reference')->nullable()->after('review_confirmation_source');
            $table->index(['business_id', 'review_request_status'], 'customers_review_request_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_review_request_status_index');
            $table->dropColumn([
                'review_request_status',
                'review_confirmed_at',
                'review_confirmation_source',
                'review_confirmation_reference',
            ]);
        });
    }
};
