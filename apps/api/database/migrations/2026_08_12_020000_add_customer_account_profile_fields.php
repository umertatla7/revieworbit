<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('industry', 80)->nullable()->after('legal_name')->index();
            $table->string('primary_email')->nullable()->after('default_country');
            $table->string('phone', 32)->nullable()->after('primary_email');
            $table->text('website_url')->nullable()->after('phone');
            $table->text('account_notes')->nullable()->after('website_url');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn([
            'legal_name', 'industry', 'primary_email', 'phone', 'website_url', 'account_notes',
        ]));
    }
};
