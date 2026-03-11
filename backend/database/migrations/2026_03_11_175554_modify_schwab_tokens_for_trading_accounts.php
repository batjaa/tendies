<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add trading_account_id column
        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('trading_account_id')->nullable()->after('id');
        });

        // 2. Data migration: create TradingAccount for each existing schwab_token
        $tokens = DB::table('schwab_tokens')->get();
        foreach ($tokens as $token) {
            $user = DB::table('users')->find($token->user_id);
            if (! $user) {
                continue;
            }

            $tradingAccountId = DB::table('trading_accounts')->insertGetId([
                'user_id' => $user->id,
                'provider' => 'schwab',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Store composite hash as single entry (original individual
            // hashes were lost when the SHA256 composite was computed)
            if ($user->schwab_account_hash) {
                DB::table('trading_account_hashes')->insert([
                    'trading_account_id' => $tradingAccountId,
                    'hash_value' => $user->schwab_account_hash,
                ]);
            }

            DB::table('schwab_tokens')
                ->where('id', $token->id)
                ->update(['trading_account_id' => $tradingAccountId]);
        }

        // 3. Drop old column, add new constraints
        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->foreign('trading_account_id')
                ->references('id')->on('trading_accounts')
                ->cascadeOnDelete();
            $table->unique('trading_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->dropForeign(['trading_account_id']);
            $table->dropUnique(['trading_account_id']);
        });

        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Backfill user_id from trading_accounts
        $tokens = DB::table('schwab_tokens')->get();
        foreach ($tokens as $token) {
            if ($token->trading_account_id) {
                $account = DB::table('trading_accounts')->find($token->trading_account_id);
                if ($account) {
                    DB::table('schwab_tokens')
                        ->where('id', $token->id)
                        ->update(['user_id' => $account->user_id]);
                }
            }
        }

        Schema::table('schwab_tokens', function (Blueprint $table) {
            $table->unique('user_id');
            $table->dropColumn('trading_account_id');
        });
    }
};
