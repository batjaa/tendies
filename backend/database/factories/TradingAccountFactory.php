<?php

namespace Database\Factories;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradingAccount> */
class TradingAccountFactory extends Factory
{
    protected $model = TradingAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'schwab',
            'display_name' => 'Individual (...' . fake()->numerify('####') . ')',
            'is_primary' => true,
        ];
    }

    public function secondary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => false,
            'display_name' => 'Joint (...' . fake()->numerify('####') . ')',
        ]);
    }
}
