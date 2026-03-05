<?php

namespace Tests\Feature;

use App\Models\SchwabToken;
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

    private function actingAsTrialUser(): User
    {
        $user = User::factory()->onTrial()->create();
        SchwabToken::factory()->for($user)->create(['token_expires_at' => now()->addMinutes(15)]);
        Passport::actingAs($user);

        return $user;
    }

    public function test_returns_transactions_from_schwab(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/hash123/transactions*' => Http::response([
                ['type' => 'TRADE', 'amount' => 100],
            ]),
        ]);

        $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => 'hash123',
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
        $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => 'hash123',
            'end' => '2026-01-31',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('start');
    }

    public function test_validation_requires_end_date(): void
    {
        $this->actingAsTrialUser();

        $response = $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => 'hash123',
            'start' => '2026-01-01',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('end');
    }

    public function test_passes_types_param_when_present(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/accounts/hash123/transactions*' => Http::response([]),
        ]);

        $this->actingAsTrialUser();

        $this->getJson('/api/v1/transactions?' . http_build_query([
            'account_hash' => 'hash123',
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
            'api.schwabapi.com/trader/v1/accounts/hash123/transactions*' => Http::response([
                ['type' => 'TRADE'],
            ]),
        ]);

        $this->actingAsTrialUser();

        $params = http_build_query([
            'account_hash' => 'hash123',
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

        $user = $this->actingAsTrialUser();

        // CLI sends RFC3339 timestamps: today midnight..tomorrow midnight (day-aligned)
        $start = now()->startOfDay()->toRfc3339String();
        $end = now()->addDay()->startOfDay()->toRfc3339String();

        $params = http_build_query([
            'account_hash' => 'hash123',
            'start' => $start,
            'end' => $end,
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$user->id}:hash123:{$start}:{$end}:";
        $this->assertTrue(Cache::has($cacheKey));

        // After 31 seconds the short TTL should have expired
        $this->travel(31)->seconds();
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_cache_long_ttl_for_past_dates(): void
    {
        Http::fake([
            'api.schwabapi.com/trader/v1/*' => Http::response([['type' => 'TRADE']]),
        ]);

        $user = $this->actingAsTrialUser();

        $params = http_build_query([
            'account_hash' => 'hash123',
            'start' => '2026-01-01',
            'end' => '2026-01-02',
        ]);

        $this->getJson("/api/v1/transactions?{$params}")->assertOk();

        $cacheKey = "schwab_txns:{$user->id}:hash123:2026-01-01:2026-01-02:";
        $this->assertTrue(Cache::has($cacheKey));

        // Should still be cached after 31 seconds (7-day TTL)
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
}
