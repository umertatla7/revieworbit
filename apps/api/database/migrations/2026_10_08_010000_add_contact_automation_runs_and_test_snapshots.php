<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('scheduled');
            $table->unsignedInteger('audience_count')->default(0);
            $table->unsignedInteger('scheduled_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('skipped_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['automation_rule_id', 'status']);
        });

        Schema::table('automation_dispatches', function (Blueprint $table): void {
            $table->dropForeign(['visit_id']);
            $table->ulid('visit_id')->nullable()->change();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
            $table->foreignUlid('automation_run_id')->nullable()->after('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->nullable()->after('automation_run_id')->constrained()->cascadeOnDelete();
            $table->unique(['automation_run_id', 'customer_id', 'sequence_number'], 'automation_run_customer_step_unique');
            $table->index(['business_id', 'customer_id', 'scheduled_for']);
        });

        Schema::table('review_links', function (Blueprint $table): void {
            $table->dropForeign(['visit_id']);
            $table->ulid('visit_id')->nullable()->change();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
        });

        Schema::table('test_message_deliveries', function (Blueprint $table): void {
            $table->text('body_snapshot')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        DB::statement('DELETE FROM automation_dispatches WHERE automation_run_id IS NOT NULL OR visit_id IS NULL');
        DB::statement('DELETE FROM review_links WHERE visit_id IS NULL');

        Schema::table('test_message_deliveries', fn (Blueprint $table) => $table->dropColumn('body_snapshot'));
        Schema::table('review_links', function (Blueprint $table): void {
            $table->dropForeign(['visit_id']);
            $table->ulid('visit_id')->nullable(false)->change();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
        });
        Schema::table('automation_dispatches', function (Blueprint $table): void {
            $table->dropUnique('automation_run_customer_step_unique');
            $table->dropIndex(['business_id', 'customer_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('automation_run_id');
            $table->dropForeign(['visit_id']);
            $table->ulid('visit_id')->nullable(false)->change();
            $table->foreign('visit_id')->references('id')->on('visits')->cascadeOnDelete();
        });
        Schema::dropIfExists('automation_runs');
    }
};
