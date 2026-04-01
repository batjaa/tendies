<?php

namespace App\Services;

use App\Models\TradingAccount;
use App\Models\TradingAccountHash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LinkAccountService
{
    public function __construct(private SchwabService $schwab) {}

    /**
     * Resolve or create a TradingAccount from Schwab OAuth callback data.
     *
     * Decision tree:
     *
     *   incoming hashes ──→ match existing TradingAccount?
     *     │                    │
     *     │ YES                │ NO
     *     │                    │
     *     ├─ same user?        ├─ link session exists?
     *     │  → refresh tokens  │  → create under session's user
     *     │                    │
     *     ├─ claimable?        └─ neither?
     *     │  (anon or never      → create anonymous User + TradingAccount
     *     │   logged in) → claim
     *     │  (FOR UPDATE lock)
     *     │
     *     └─ other registered?
     *        → reject 409
     *
     * @return array{user: User, trading_account: TradingAccount, is_new_user: bool, is_new_account: bool}
     */
    public function resolveOrCreateAccount(
        array $hashes,
        array $tokenData,
        ?User $authenticatedUser = null,
    ): array {
        // Check if any incoming hash already belongs to an existing TradingAccount.
        $existingHash = TradingAccountHash::whereIn('hash_value', $hashes)
            ->with('tradingAccount.user')
            ->first();

        if ($existingHash) {
            return $this->handleExistingAccount($existingHash->tradingAccount, $tokenData, $authenticatedUser);
        }

        return $this->handleNewAccount($hashes, $tokenData, $authenticatedUser);
    }

    /**
     * An existing TradingAccount was found by hash match.
     */
    private function handleExistingAccount(
        TradingAccount $tradingAccount,
        array $tokenData,
        ?User $authenticatedUser,
    ): array {
        $existingUser = $tradingAccount->user;

        // Same user reconnecting (explicit auth or anonymous PKCE re-auth) — just refresh tokens.
        if (! $authenticatedUser || $authenticatedUser->id === $existingUser->id) {
            $this->schwab->storeTokens($tradingAccount, $tokenData);

            return ['user' => $existingUser, 'trading_account' => $tradingAccount, 'is_new_user' => false, 'is_new_account' => false];
        }

        // Claimable if the existing owner never authenticated themselves:
        // anonymous (no email) or has no Passport tokens (auto-created by old
        // waitlist flow with a random password, never logged in from CLI/app).
        if ($this->isClaimable($existingUser)) {
            return DB::transaction(function () use ($tradingAccount, $existingUser, $tokenData, $authenticatedUser) {
                $lockedUser = User::lockForUpdate()->find($existingUser->id);

                if (! $this->isClaimable($lockedUser)) {
                    abort(409, 'This Schwab account is already linked to another user');
                }

                if ($authenticatedUser) {
                    $tradingAccount->update(['user_id' => $authenticatedUser->id]);
                    $this->schwab->storeTokens($tradingAccount, $tokenData);
                    $lockedUser->delete();

                    return ['user' => $authenticatedUser, 'trading_account' => $tradingAccount->fresh(), 'is_new_user' => false, 'is_new_account' => false];
                }

                // No authenticated user — just refresh the anonymous user's tokens.
                $this->schwab->storeTokens($tradingAccount, $tokenData);

                return ['user' => $lockedUser, 'trading_account' => $tradingAccount, 'is_new_user' => false, 'is_new_account' => false];
            });
        }

        // Owned by a different registered user — reject.
        abort(409, 'This Schwab account is already linked to another user');
    }

    /**
     * No existing TradingAccount found — create new.
     */
    private function handleNewAccount(
        array $hashes,
        array $tokenData,
        ?User $authenticatedUser,
    ): array {
        $isNewUser = false;

        if ($authenticatedUser) {
            $user = $authenticatedUser;
        } else {
            // Anonymous user.
            $user = User::create([
                'name' => 'Schwab User',
                'password' => Str::random(32),
            ]);
            $isNewUser = true;
        }

        // Grant trial if user doesn't have a subscription or trial.
        if (! $user->trial_ends_at && ! $user->subscribed('default') && ! $user->hasProGrant()) {
            $user->update(['trial_ends_at' => now()->addDays(7)]);
        }

        $tradingAccount = $this->createTradingAccount($user, $hashes, $tokenData);

        return ['user' => $user, 'trading_account' => $tradingAccount, 'is_new_user' => $isNewUser, 'is_new_account' => true];
    }

    /**
     * A user is claimable if they never authenticated themselves:
     * anonymous (no email) or auto-created with no Passport tokens.
     */
    private function isClaimable(User $user): bool
    {
        return $user->isAnonymous() || $user->tokens()->count() === 0;
    }

    private function createTradingAccount(User $user, array $hashes, array $tokenData): TradingAccount
    {
        $isPrimary = $user->tradingAccounts()->count() === 0;

        $tradingAccount = TradingAccount::create([
            'user_id' => $user->id,
            'provider' => 'schwab',
            'is_primary' => $isPrimary,
        ]);

        foreach ($hashes as $hash) {
            $tradingAccount->hashes()->create(['hash_value' => $hash]);
        }

        $this->schwab->storeTokens($tradingAccount, $tokenData);

        return $tradingAccount;
    }
}
