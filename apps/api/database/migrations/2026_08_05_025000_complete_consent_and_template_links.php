<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_consents', function (Blueprint $table): void {
            $table->string('disclosure_version')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::table('suppression_entries', function (Blueprint $table): void {
            $table->string('phone_e164', 32)->nullable();
            $table->string('source')->default('manual');
        });
        Schema::table('message_templates', function (Blueprint $table): void {
            $table->foreignUlid('media_template_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->dropConstrainedForeignId('media_template_id'));
        Schema::table('suppression_entries', fn (Blueprint $table) => $table->dropColumn(['phone_e164', 'source']));
        Schema::table('customer_consents', fn (Blueprint $table) => $table->dropColumn(['disclosure_version', 'consented_at', 'revoked_at']));
    }
};
