<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LinkRouteTest extends TestCase
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

    public function test_valid_session_redirects_to_schwab(): void
    {
        $sessionId = '00000000-0000-4000-8000-000000000001';
        Cache::put("link_session:{$sessionId}", [
            'user_id' => 1,
            'provider' => 'schwab',
        ], now()->addMinutes(10));

        $response = $this->get("/auth/link/{$sessionId}");

        $response->assertRedirect();
        $this->assertStringContainsString('api.schwabapi.com/v1/oauth/authorize', $response->headers->get('Location'));
    }

    public function test_expired_session_returns_403(): void
    {
        $response = $this->get('/auth/link/00000000-0000-4000-8000-000000000002');

        $response->assertStatus(403);
    }

    public function test_binds_link_session_to_oauth_state(): void
    {
        $sessionId = '00000000-0000-4000-8000-000000000001';
        Cache::put("link_session:{$sessionId}", [
            'user_id' => 1,
            'provider' => 'schwab',
        ], now()->addMinutes(10));

        $response = $this->get("/auth/link/{$sessionId}");
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertEquals($sessionId, Cache::get("schwab_state:{$query['state']}")['link_session_id']);
    }
}
