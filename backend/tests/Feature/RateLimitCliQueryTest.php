<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class RateLimitCliQueryTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a test route with rate-limit-cli middleware.
        Route::middleware(['auth:api', 'rate-limit-cli'])->get('/test/rate-limited', function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_pro_user_bypasses_rate_limit(): void
    {
        $user = $this->createSubscribedUser();
        Passport::actingAs($user);

        $response = $this->getJson('/test/rate-limited');

        $response->assertOk();
    }

    public function test_free_user_under_limit_passes(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/test/rate-limited', [
            'X-Query-ID' => 'query-1',
            'X-Timezone' => 'America/New_York',
        ]);

        $response->assertOk();
        $this->assertEquals(User::FREE_DAILY_LIMIT - 1, $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_retry_same_query_id_does_not_double_count(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $this->getJson('/test/rate-limited', ['X-Query-ID' => 'query-1'])->assertOk();
        $response = $this->getJson('/test/rate-limited', ['X-Query-ID' => 'query-1'])->assertOk();

        // Count should still be 1 (not 2).
        $this->assertEquals(User::FREE_DAILY_LIMIT - 1, $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_free_user_at_limit_gets_429(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        // Fill up the limit.
        for ($i = 1; $i <= User::FREE_DAILY_LIMIT; $i++) {
            $this->getJson('/test/rate-limited', ['X-Query-ID' => "query-{$i}"])->assertOk();
        }

        // Next unique query should be rejected.
        $response = $this->getJson('/test/rate-limited', ['X-Query-ID' => 'query-over-limit']);

        $response->assertStatus(429)
            ->assertJson(['error' => 'rate_limit_exceeded']);
    }

    public function test_missing_query_id_returns_400(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/test/rate-limited');

        $response->assertStatus(400)
            ->assertJson(['error' => 'missing_query_id']);
    }
}
