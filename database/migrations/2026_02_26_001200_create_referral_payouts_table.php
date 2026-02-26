<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referral_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('milestone');
            $table->decimal('amount', 12, 2);
            $table->string('currency')->default('NGN');
            $table->timestamp('credited_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['referred_user_id', 'milestone']);
            $table->index(['referrer_user_id', 'created_at']);
            $table->index(['beneficiary_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_payouts');
    }
};

