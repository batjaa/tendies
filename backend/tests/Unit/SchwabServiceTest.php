<?php

namespace Tests\Unit;

use App\Exceptions\SchwabAuthException;
use App\Models\SchwabToken;
use App\Models\User;
use App\Services\SchwabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchwabServiceTest extends TestCase
{
    use RefreshDatabase;

    private SchwabService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SchwabService;

        config([
            'schwab.client_id' => 'test-client-id',
            'schwab.client_secret' => 'test-client-secret',
            'schwab.redirect_uri' => 'https://example.com/callback',
            'schwab.authorize_url' => 'https://api.schwabapi.com/v1/oauth/authorize',
            'schwab.token_url' => 'https://api.schwabapi.com/v1/oauth/token',
            'schwab.api_base_url' => 'https://api.schwabapi.com/trader/v1',
        ]);
    }

    public function test_get_authorize_url_builds_correct_url(): void
    {
        $url = $this->service->getAuthorizeUrl('test-state');

        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://example.com/callback'), $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=test-state', $url);
        $this->assertStringStartsWith('https://api.schwabapi.com/v1/oauth/authorize?', $url);
    }

    public function test_exchange_code_success(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
        ]);

        $result = $this->service->exchangeCode('auth-code');

        $this->assertEquals('new-access', $result['access_token']);
        $this->assertEquals('new-refresh', $result['refresh_token']);
    }

    public function test_exchange_code_failure_throws_runtime_exception(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response('bad', 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schwab token exchange failed');

        $this->service->exchangeCode('bad-code');
    }

    public function test_refresh_token_success(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 1800,
            ]),
        ]);

        $result = $this->service->refreshToken('old-refresh');

        $this->assertEquals('refreshed-access', $result['access_token']);
    }

    public function test_refresh_token_failure_throws_schwab_auth_exception(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response('bad', 401),
        ]);

        $this->expectException(SchwabAuthException::class);

        $this->service->refreshToken('expired-refresh');
    }

    public function test_store_tokens_creates_new_token(): void
    {
        $user = User::factory()->create();

        $this->service->storeTokens($user, [
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-123',
            'expires_in' => 1800,
        ]);

        $this->assertDatabaseCount('schwab_tokens', 1);
        $token = $user->schwabToken()->first();
        $this->assertEquals('access-123', $token->encrypted_access_token);
        $this->assertEquals('refresh-123', $token->encrypted_refresh_token);
    }

    public function test_store_tokens_updates_existing_token(): void
    {
        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create();

        $this->service->storeTokens($user, [
            'access_token' => 'updated-access',
            'refresh_token' => 'updated-refresh',
            'expires_in' => 1800,
        ]);

        $this->assertDatabaseCount('schwab_tokens', 1);
        $token = $user->schwabToken()->first();
        $this->assertEquals('updated-access', $token->encrypted_access_token);
    }

    public function test_get_valid_access_token_returns_current_if_valid(): void
    {
        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create([
            'encrypted_access_token' => 'valid-token',
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $token = $this->service->getValidAccessToken($user);

        $this->assertEquals('valid-token', $token);
        Http::assertNothingSent();
    }

    public function test_get_valid_access_token_refreshes_if_expired(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 1800,
            ]),
        ]);

        $user = User::factory()->create();
        SchwabToken::factory()->expired()->for($user)->create();

        $token = $this->service->getValidAccessToken($user);

        $this->assertEquals('refreshed-access', $token);
    }

    public function test_get_valid_access_token_throws_if_no_token(): void
    {
        $user = User::factory()->create();

        $this->expectException(SchwabAuthException::class);
        $this->expectExceptionMessage('No Schwab token found');

        $this->service->getValidAccessToken($user);
    }

    public function test_make_request_calls_api_with_token(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts*' => Http::response([
                ['accountNumber' => '123'],
            ]),
        ]);

        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create([
            'encrypted_access_token' => 'my-token',
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $result = $this->service->makeRequest($user, 'get', '/accounts/accountNumbers');

        $this->assertCount(1, $result);
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-token');
        });
    }

    public function test_make_request_throws_runtime_exception_on_5xx(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response('error', 500),
        ]);

        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create([
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schwab API request failed');

        $this->service->makeRequest($user, 'get', '/accounts/accountNumbers');
    }

    public function test_make_request_retries_once_on_401_then_succeeds(): void
    {
        Http::fakeSequence('api.schwabapi.com/trader/v1/accounts*')
            ->push('unauthorized', 401)
            ->push([['accountNumber' => '123']]);

        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 1800,
            ]),
        ]);

        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create([
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $result = $this->service->makeRequest($user, 'get', '/accounts/accountNumbers');

        $this->assertCount(1, $result);
    }

    public function test_make_request_throws_schwab_auth_exception_on_persistent_401(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response('unauthorized', 401),
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 1800,
            ]),
        ]);

        $user = User::factory()->create();
        SchwabToken::factory()->for($user)->create([
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $this->expectException(SchwabAuthException::class);
        $this->expectExceptionMessage('Schwab API request failed (HTTP 401)');

        $this->service->makeRequest($user, 'get', '/accounts/accountNumbers');
    }

    public function test_fetch_account_hash_returns_sha256_of_sorted_hashes(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response([
                ['accountNumber' => '111', 'hashValue' => 'hash-b'],
                ['accountNumber' => '222', 'hashValue' => 'hash-a'],
            ]),
        ]);

        $result = $this->service->fetchAccountHash('some-token');

        $expected = hash('sha256', 'hash-a:hash-b');
        $this->assertEquals($expected, $result);
    }

    public function test_fetch_account_hash_throws_on_empty_accounts(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response([]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No Schwab accounts found');

        $this->service->fetchAccountHash('some-token');
    }

    public function test_fetch_account_hash_throws_on_api_failure(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response('error', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch Schwab account numbers');

        $this->service->fetchAccountHash('some-token');
    }
}
