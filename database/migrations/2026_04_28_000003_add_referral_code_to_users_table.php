<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 10)->nullable()->unique()->after('status');
            $table->boolean('gdpr_data_requested')->default(false)->after('referral_code');
            $table->timestamp('gdpr_requested_at')->nullable()->after('gdpr_data_requested');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'gdpr_data_requested', 'gdpr_requested_at']);
        });
    }
};
