<?php

namespace Database\Factories;

use App\Models\SchwabToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchwabToken> */
class SchwabTokenFactory extends Factory
{
    protected $model = SchwabToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'encrypted_access_token' => 'fake-access-token',
            'encrypted_refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addMinutes(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subMinute(),
        ]);
    }
}
