<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class TransactionTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    private function actingAsTrialUser(): array
    {
        $user = User::factory()->onTrial()->create();
        $account = $this->createTradingAccountWithToken($user);
        Passport::actingAs($user);

        $hashValue = $account->hashes->first()->hash_value;

        return [$user, $account, $hashValue];
    }

    public function test_returns_transactions_from_schwab(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/*/transactions*' => Http::response([
                ['type' => 'TRADE', 'amount' => 100],
            ]),
        ]);

        [, , $hash] = $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => $hash,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        $response->assertOk()
            ->assertJson([['type' => 'TRADE', 'amount' => 100]]);
    }

    public function test_validation_requires_account_hash(): void
    {
        $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('account_hash');
    }

    public function test_validation_requires_start_date(): void
    {
        [, , $hash] = $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => $hash,
            'end' => '2026-01-31',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('start');
    }

    public function test_validation_requires_end_date(): void
    {
        [, , $hash] = $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => $hash,
            'start' => '2026-01-01',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('end');
    }

    public function test_passes_types_param_when_present(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/*/transactions*' => Http::response([]),
        ]);

        [, , $hash] = $this->actingAsTrialUser();

        $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => $hash,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
            'types' => 'TRADE',
        ]));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'types=TRADE');
        });
    }

    public function test_caches_responses(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/*/transactions*' => Http::response([
                ['type' => 'TRADE'],
            ]),
        ]);

        [, , $hash] = $this->actingAsTrialUser();

        $params = http_build_query([
            'account_hash' => $hash,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();
        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        Http::assertSentCount(1);
    }

    public function test_cache_short_ttl_when_range_covers_today(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['type' => 'TRADE']]),
        ]);

        [, $account, $hash] = $this->actingAsTrialUser();

        $start = now()->startOfDay()->toRfc3339String();
        $end = now()->addDay()->startOfDay()->toRfc3339String();

        $params = http_build_query([
            'account_hash' => $hash,
            'start' => $start,
            'end' => $end,
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$account->id}:{$hash}:{$start}:{$end}:";
        $this->assertTrue(Cache::has($cacheKey));

        $this->travel(31)->seconds();
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_cache_short_ttl_when_cli_sends_pst_timestamps_to_utc_server(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['type' => 'TRADE']]),
        ]);

        [, $account, $hash] = $this->actingAsTrialUser();

        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');
        $start = "{$today}T00:00:00-08:00";
        $end = "{$tomorrow}T00:00:00-08:00";

        $params = http_build_query([
            'account_hash' => $hash,
            'start' => $start,
            'end' => $end,
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$account->id}:{$hash}:{$start}:{$end}:";
        $this->assertTrue(Cache::has($cacheKey));

        $this->travel(31)->seconds();
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_cache_short_ttl_when_cli_sends_est_timestamps_to_utc_server(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['type' => 'TRADE']]),
        ]);

        [, $account, $hash] = $this->actingAsTrialUser();

        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');
        $start = "{$today}T00:00:00-05:00";
        $end = "{$tomorrow}T00:00:00-05:00";

        $params = http_build_query([
            'account_hash' => $hash,
            'start' => $start,
            'end' => $end,
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$account->id}:{$hash}:{$start}:{$end}:";
        $this->assertTrue(Cache::has($cacheKey));

        $this->travel(31)->seconds();
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_cache_long_ttl_for_past_dates(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['type' => 'TRADE']]),
        ]);

        [, $account, $hash] = $this->actingAsTrialUser();

        $params = http_build_query([
            'account_hash' => $hash,
            'start' => '2026-01-01',
            'end' => '2026-01-02',
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$account->id}:{$hash}:2026-01-01:2026-01-02:";
        $this->assertTrue(Cache::has($cacheKey));

        $this->travel(31)->seconds();
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/transactions');

        $response->assertUnauthorized();
    }

    public function test_requires_subscription(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => 'hash123',
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        $response->assertForbidden();
    }

    public function test_rejects_account_hash_not_owned_by_user(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([]),
        ]);

        // Create two users with separate trading accounts.
        $user1 = User::factory()->onTrial()->create();
        $account1 = $this->createTradingAccountWithToken($user1);
        $hash1 = $account1->hashes->first()->hash_value;

        $user2 = User::factory()->onTrial()->create();
        $this->createTradingAccountWithToken($user2);
        Passport::actingAs($user2);

        // User 2 tries to query user 1's account hash.
        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => $hash1,
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        $response->assertForbidden();
    }
}
