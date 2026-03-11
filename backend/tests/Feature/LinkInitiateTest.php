<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class LinkInitiateTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    public function test_pro_user_can_initiate_link(): void
    {
        $user = $this->createSubscribedUser();
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/link/initiate', ['provider' => 'schwab']);

        $response->assertOk()
            ->assertJsonStructure(['link_session_id', 'authorize_url']);

        $sessionId = $response->json('link_session_id');
        $this->assertNotNull(Cache::get("link_session:{$sessionId}"));
    }

    public function test_free_user_with_existing_account_is_blocked(): void
    {
        $user = User::factory()->onTrial()->create(['trial_ends_at' => now()->subDay()]);
        $this->createTradingAccountWithToken($user);
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/link/initiate', ['provider' => 'schwab']);

        $response->assertForbidden()
            ->assertJson(['error' => 'account_limit_reached']);
    }

    public function test_free_user_without_account_can_initiate(): void
    {
        // Free user with no trading accounts can still link their first.
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/link/initiate', ['provider' => 'schwab']);

        $response->assertOk()
            ->assertJsonStructure(['link_session_id', 'authorize_url']);
    }

    public function test_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/link/initiate', ['provider' => 'schwab']);

        $response->assertUnauthorized();
    }
}
