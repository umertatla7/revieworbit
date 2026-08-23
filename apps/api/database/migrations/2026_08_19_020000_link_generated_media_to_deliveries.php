<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_media', function (Blueprint $table): void {
            $table->char('access_token_hash', 64)->nullable()->unique()->after('failure_message');
        });

        Schema::table('message_deliveries', function (Blueprint $table): void {
            $table->foreignUlid('generated_media_id')->nullable()->after('review_link_id')->constrained('generated_media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_deliveries', fn (Blueprint $table) => $table->dropConstrainedForeignId('generated_media_id'));
        Schema::table('generated_media', fn (Blueprint $table) => $table->dropColumn('access_token_hash'));
    }
};
