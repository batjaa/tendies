<?php

namespace Tests\Feature;

use App\Models\SchwabToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class EnsureSubscribedMiddlewareTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/accounts');

        $response->assertUnauthorized();
    }

    public function test_expired_trial_no_subscription_returns_403(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        Http::fake(['*' => Http::response([])]);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertForbidden()
            ->assertJson(['error' => 'subscription_required']);
    }

    public function test_active_trial_passes(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['accountNumber' => '123']]),
        ]);

        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertOk();
    }

    public function test_active_subscription_passes(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['accountNumber' => '123']]),
        ]);

        $user = $this->createSubscribedUser();
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertOk();
    }

    public function test_past_due_subscription_blocked(): void
    {
        // Cashier v16 deactivates past_due subscriptions by default.
        $user = $this->createSubscribedUser('past_due');
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertForbidden()
            ->assertJson(['error' => 'subscription_required']);
    }
}
