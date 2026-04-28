<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'rewarded'])->default('pending');
            $table->decimal('bonus_amount', 10, 2)->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->unique('referred_id'); // one referral per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
