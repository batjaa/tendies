<?php

namespace Tests\Feature;

use App\Models\SchwabToken;
use App\Models\User;
use App\Services\SchwabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SchwabCallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $validState;

    private string $passportAuthorizeUrl;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'schwab.client_id' => 'test-client-id',
            'schwab.client_secret' => 'test-client-secret',
            'schwab.redirect_uri' => 'https://example.com/callback',
            'schwab.token_url' => 'https://api.schwabapi.com/v1/oauth/token',
            'schwab.api_base_url' => 'https://api.schwabapi.com/trader/v1',
        ]);

        $this->validState = bin2hex(random_bytes(16));
        $this->passportAuthorizeUrl = config('app.url') . '/oauth/authorize?client_id=1&state=passport-state-123';

        Cache::put("schwab_state:{$this->validState}", $this->passportAuthorizeUrl, now()->addMinutes(10));
    }

    private function fakeSuccessfulSchwabApi(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response([
                ['accountNumber' => '111', 'hashValue' => 'hash-a'],
            ]),
        ]);
    }

    private function expectedAccountHash(): string
    {
        return hash('sha256', 'hash-a');
    }

    // --- State validation ---

    public function test_missing_state_returns_400(): void
    {
        $response = $this->get('/auth/schwab/callback?code=some-code');

        $response->assertStatus(400);
    }

    public function test_malformed_state_returns_400(): void
    {
        $response = $this->get('/auth/schwab/callback?state=not-hex&code=some-code');

        $response->assertStatus(400);
    }

    public function test_unknown_state_returns_403(): void
    {
        $unknownState = bin2hex(random_bytes(16));

        $response = $this->get("/auth/schwab/callback?state={$unknownState}&code=some-code");

        $response->assertStatus(403);
    }

    public function test_missing_code_returns_400(): void
    {
        $response = $this->get("/auth/schwab/callback?state={$this->validState}");

        $response->assertStatus(400);
    }

    public function test_foreign_redirect_host_returns_400(): void
    {
        $state = bin2hex(random_bytes(16));
        Cache::put("schwab_state:{$state}", 'https://evil.com/oauth/authorize?state=x', now()->addMinutes(10));

        $response = $this->get("/auth/schwab/callback?state={$state}&code=some-code");

        $response->assertStatus(400);
    }

    // --- New user creation ---

    public function test_new_user_created_with_account_hash_and_trial(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $response->assertRedirect();

        $hash = $this->expectedAccountHash();
        $this->assertDatabaseHas('users', ['schwab_account_hash' => $hash]);

        $user = User::where('schwab_account_hash', $hash)->first();
        $this->assertNotNull($user->trial_ends_at);
        $this->assertTrue($user->trial_ends_at->isFuture());
        $this->assertDatabaseHas('schwab_tokens', ['user_id' => $user->id]);
    }

    // --- Returning user ---

    public function test_returning_user_recognized_by_account_hash(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $existingUser = User::factory()->withSchwabHash($this->expectedAccountHash())->onTrial()->create();

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $response->assertRedirect();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('schwab_tokens', ['user_id' => $existingUser->id]);
    }

    public function test_trial_not_reset_for_returning_user(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $trialEnd = now()->addDays(3);
        User::factory()->withSchwabHash($this->expectedAccountHash())->create([
            'trial_ends_at' => $trialEnd,
        ]);

        $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $user = User::where('schwab_account_hash', $this->expectedAccountHash())->first();
        $this->assertEquals($trialEnd->format('Y-m-d H:i:s'), $user->trial_ends_at->format('Y-m-d H:i:s'));
    }

    // --- Legacy user adoption ---

    public function test_legacy_user_adopted_by_account_hash(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $legacyUser = User::factory()->create([
            'email' => 'old-hash@schwab.local',
            'schwab_account_hash' => null,
        ]);
        SchwabToken::factory()->for($legacyUser)->create();

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $response->assertRedirect();
        $this->assertDatabaseCount('users', 1);

        $legacyUser->refresh();
        $this->assertEquals($this->expectedAccountHash(), $legacyUser->schwab_account_hash);
    }

    // --- Redirect and Passport state caching ---

    public function test_redirects_to_passport_authorize_url(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $response->assertRedirect($this->passportAuthorizeUrl);
    }

    public function test_caches_user_id_by_passport_state(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $cachedUserId = Cache::get('passport_user:passport-state-123');
        $this->assertNotNull($cachedUserId);

        $user = User::where('schwab_account_hash', $this->expectedAccountHash())->first();
        $this->assertEquals($user->id, $cachedUserId);
    }

    public function test_state_consumed_single_use(): void
    {
        $this->fakeSuccessfulSchwabApi();

        $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $this->assertNull(Cache::get("schwab_state:{$this->validState}"));

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");
        $response->assertStatus(403);
    }

    // --- Schwab API failures ---

    public function test_schwab_token_exchange_failure_returns_500(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response('bad', 400),
        ]);

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=bad-code");

        $response->assertInternalServerError();
    }

    public function test_schwab_account_hash_failure_returns_500(): void
    {
        Http::fake([
            'api.schwabapi.com/v1/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 1800,
            ]),
            'api.schwabapi.com/trader/v1/accounts/accountNumbers' => Http::response('error', 500),
        ]);

        $response = $this->get("/auth/schwab/callback?state={$this->validState}&code=auth-code");

        $response->assertInternalServerError();
    }
}
