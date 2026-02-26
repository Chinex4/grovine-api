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
        if (! Schema::hasColumn('users', 'wallet_balance')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->decimal('wallet_balance', 12, 2)->default(0)->after('profile_picture');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'wallet_balance')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('wallet_balance');
            });
        }
    }
};
