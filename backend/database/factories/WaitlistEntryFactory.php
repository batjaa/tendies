<?php

namespace Database\Factories;

use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WaitlistEntry> */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'status' => 'pending',
        ];
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invited',
            'invite_token' => Str::random(64),
            'invited_at' => now(),
            'invite_expires_at' => now()->addDays(7),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'invited',
            'invite_token' => Str::random(64),
            'invited_at' => now()->subDays(8),
            'invite_expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'invite_token' => Str::random(64),
            'invited_at' => now()->subDays(3),
            'accepted_at' => now(),
        ]);
    }
}
