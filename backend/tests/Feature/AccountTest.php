<?php

namespace Tests\Feature;

use App\Models\SchwabToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class AccountTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    public function test_returns_account_numbers_from_schwab(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response([
                ['accountNumber' => '12345', 'hashValue' => 'abc'],
            ]),
        ]);

        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJson([['accountNumber' => '12345', 'hashValue' => 'abc']]);
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/accounts');

        $response->assertUnauthorized();
    }

    public function test_requires_subscription(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertForbidden()
            ->assertJson(['error' => 'subscription_required']);
    }

    public function test_schwab_api_failure_returns_500(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response('error', 500),
        ]);

        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertInternalServerError();
    }

    public function test_expired_token_triggers_refresh_then_succeeds(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 1800,
            ]),
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response([
                ['accountNumber' => '999'],
            ]),
        ]);

        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->expired()->for($user)->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJson([['accountNumber' => '999']]);
    }

    public function test_schwab_auth_failure_returns_401(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response('unauthorized', 401),
        ]);

        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->expired()->for($user)->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/accounts');

        $response->assertUnauthorized()
            ->assertJson(['error' => 'schwab_token_expired']);
    }
}
