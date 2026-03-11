<?php

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistVerifyTest extends TestCase
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

    public function test_valid_invite_redirects_to_schwab(): void
    {
        $entry = WaitlistEntry::factory()->invited()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertRedirect();
        $this->assertStringContainsString('api.schwabapi.com/v1/oauth/authorize', $response->headers->get('Location'));
        $this->assertEquals($entry->invite_token, session('waitlist_invite_token'));
    }

    public function test_expired_invite_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->expired()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertStatus(403);
    }

    public function test_invalid_token_returns_403(): void
    {
        $response = $this->get('/auth/waitlist/verify?token=nonexistent');

        $response->assertStatus(403);
    }

    public function test_already_accepted_returns_403(): void
    {
        $entry = WaitlistEntry::factory()->accepted()->create();

        $response = $this->get("/auth/waitlist/verify?token={$entry->invite_token}");

        $response->assertStatus(403);
    }
}
