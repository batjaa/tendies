<?php

namespace App\Policies;

use App\Models\TradingAccount;
use App\Models\User;

class TradingAccountPolicy
{
    public function update(User $user, TradingAccount $tradingAccount): bool
    {
        return $tradingAccount->user_id === $user->id;
    }

    public function delete(User $user, TradingAccount $tradingAccount): bool
    {
        return $tradingAccount->user_id === $user->id;
    }

    public function setPrimary(User $user, TradingAccount $tradingAccount): bool
    {
        return $tradingAccount->user_id === $user->id;
    }
}
