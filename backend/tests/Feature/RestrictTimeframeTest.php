<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class RestrictTimeframeTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:api', 'restrict-timeframe'])->get('/test/timeframe', function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_pro_user_bypasses_timeframe_restriction(): void
    {
        $user = $this->createSubscribedUser();
        Passport::actingAs($user);

        $response = $this->getJson('/test/timeframe?' . http_build_query([
            'start' => '2026-01-01',
            'end' => '2026-12-31',
        ]));

        $response->assertOk();
    }

    public function test_free_user_within_7_days_passes(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/test/timeframe?' . http_build_query([
            'start' => '2026-03-01',
            'end' => '2026-03-07',
        ]));

        $response->assertOk();
    }

    public function test_free_user_exceeding_7_days_blocked(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/test/timeframe?' . http_build_query([
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        $response->assertForbidden()
            ->assertJson([
                'error' => 'timeframe_restricted',
                'max_days' => User::FREE_MAX_DAYS,
            ]);
    }
}
