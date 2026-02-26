<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->foreignUuid('referred_by_user_id')
                    ->nullable()
                    ->after('referral_code')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        $this->backfillUniqueReferralCodes();

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('referral_code');
            $table->index('referred_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['referred_by_user_id']);
            $table->dropUnique(['referral_code']);
            $table->dropConstrainedForeignId('referred_by_user_id');
        });
    }

    private function backfillUniqueReferralCodes(): void
    {
        $used = [];

        $users = DB::table('users')
            ->select(['id', 'name', 'referral_code'])
            ->orderBy('created_at')
            ->get();

        foreach ($users as $user) {
            $current = strtoupper(trim((string) $user->referral_code));

            if ($current !== '' && ! isset($used[$current])) {
                $used[$current] = true;

                if ($current !== $user->referral_code) {
                    DB::table('users')->where('id', $user->id)->update(['referral_code' => $current]);
                }

                continue;
            }

            $code = $this->generateCode((string) $user->name, $used);
            $used[$code] = true;

            DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
        }
    }

    /**
     * @param array<string, bool> $used
     */
    private function generateCode(string $name, array $used): string
    {
        $prefix = strtoupper(Str::of($name)->replaceMatches('/[^A-Za-z0-9]/', '')->substr(0, 4)->value());
        $prefix = $prefix !== '' ? $prefix : 'GRV';

        do {
            $code = $prefix.Str::upper(Str::random(5));
        } while (isset($used[$code]));

        return $code;
    }
};

