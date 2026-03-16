<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'schwab.client_id' => 'test-client-id',
            'schwab.authorize_url' => 'https://api.schwabapi.com/v1/oauth/authorize',
            'schwab.redirect_uri' => 'https://example.com/callback',
        ]);
    }

    public function test_connect_requires_auth(): void
    {
        $response = $this->get('/onboarding/connect');

        $response->assertRedirect();
    }

    public function test_connect_shows_page_for_authenticated_user(): void
    {
        $user = User::factory()->onTrial()->create();

        $response = $this->actingAs($user)->get('/onboarding/connect');

        $response->assertStatus(200);
        $response->assertSee('Connect your brokerage');
    }

    public function test_connect_schwab_requires_auth(): void
    {
        $response = $this->get('/onboarding/connect/schwab');

        $response->assertRedirect();
    }

    public function test_connect_schwab_redirects_to_schwab_oauth(): void
    {
        $user = User::factory()->onTrial()->create();

        $response = $this->actingAs($user)->get('/onboarding/connect/schwab');

        $response->assertRedirect();
        $this->assertStringContainsString('api.schwabapi.com/v1/oauth/authorize', $response->headers->get('Location'));
    }

    public function test_complete_requires_auth(): void
    {
        $response = $this->get('/onboarding/complete');

        $response->assertRedirect();
    }

    public function test_complete_shows_page_with_user_data(): void
    {
        $user = User::factory()->onTrial()->create(['email' => 'trader@example.com']);

        $response = $this->actingAs($user)->get('/onboarding/complete');

        $response->assertStatus(200);
        $response->assertSee('trader@example.com');
        $response->assertSee('all set.', false);
    }
}
