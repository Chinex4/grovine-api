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
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'account_status')) {
                $table->string('account_status')->default('active')->after('role')->index();
            }

            if (! Schema::hasColumn('users', 'suspended_until')) {
                $table->timestamp('suspended_until')->nullable()->after('account_status')->index();
            }

            if (! Schema::hasColumn('users', 'suspension_reason')) {
                $table->text('suspension_reason')->nullable()->after('suspended_until');
            }

            if (! Schema::hasColumn('users', 'warning_count')) {
                $table->unsignedInteger('warning_count')->default(0)->after('suspension_reason');
            }

            if (! Schema::hasColumn('users', 'last_warned_at')) {
                $table->timestamp('last_warned_at')->nullable()->after('warning_count');
            }

            if (! Schema::hasColumn('users', 'banned_at')) {
                $table->timestamp('banned_at')->nullable()->after('last_warned_at');
            }

            if (! Schema::hasColumn('users', 'banned_reason')) {
                $table->text('banned_reason')->nullable()->after('banned_at');
            }

            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('banned_reason')->index();
            }
        });

        Schema::create('user_daily_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'activity_date']);
            $table->index(['activity_date', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_daily_activities');

        Schema::table('users', function (Blueprint $table): void {
            $columns = [
                'account_status',
                'suspended_until',
                'suspension_reason',
                'warning_count',
                'last_warned_at',
                'banned_at',
                'banned_reason',
                'last_seen_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
