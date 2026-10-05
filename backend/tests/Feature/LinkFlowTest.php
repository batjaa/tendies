<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LinkFlowTest extends TestCase
{
    use RefreshDatabase;

    private function startLink(User $user): array
    {
        Passport::actingAs($user);
        $link = $this->postJson('/api/v1/link/initiate', ['provider' => 'schwab'])
            ->assertOk()->json();
        $url = $this->get($link['authorize_url'])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return [$link['link_session_id'], $query['state']];
    }

    private function fakeSchwab(): void
    {
        config(['schwab.token_url' => 'https://schwab.test/token', 'schwab.api_base_url' => 'https://schwab.test/trader']);
        Http::fake([
            'schwab.test/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 1800]),
            'schwab.test/trader/accounts/accountNumbers' => Http::response([['hashValue' => 'linked-hash']]),
        ]);
    }

    public function test_callback_completes_the_exact_attempt_without_browser_session(): void
    {
        $this->fakeSchwab();
        $owner = User::factory()->onTrial()->create();
        [$id, $state] = $this->startLink($owner);
        $this->getJson("/api/v1/link/{$id}/status")->assertOk()->assertJson(['status' => 'pending']);
        $this->flushSession();

        $callback = $this->get("/auth/schwab/callback?state={$state}&code=code")->assertRedirect();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('trading_accounts', ['user_id' => $owner->id]);
        $this->getJson("/api/v1/link/{$id}/status")->assertOk()->assertJson(['status' => 'linked']);
        $this->get($callback->headers->get('Location'))->assertOk()->assertSee('Account linked');
        // Refreshing the success page must not attempt to reuse the consumed link session.
        $this->get($callback->headers->get('Location'))->assertOk();
        $this->get("/auth/schwab/callback?state={$state}&code=code")->assertForbidden();
        $this->getJson("/api/v1/link/{$id}/status")->assertOk()->assertJson(['status' => 'linked']);
    }

    public function test_status_is_private_to_the_user_who_initiated_linking(): void
    {
        [$id] = $this->startLink(User::factory()->onTrial()->create());
        Passport::actingAs(User::factory()->create());
        $this->getJson("/api/v1/link/{$id}/status")->assertNotFound();
    }

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/link/00000000-0000-4000-8000-000000000001/status')->assertUnauthorized();
    }

    public function test_expired_status_cannot_report_success(): void
    {
        [$id] = $this->startLink(User::factory()->onTrial()->create());
        $this->travel(11)->minutes();
        $this->getJson("/api/v1/link/{$id}/status")->assertStatus(410);
    }

    public function test_provider_failure_reaches_the_waiting_cli(): void
    {
        config(['schwab.token_url' => 'https://schwab.test/token']);
        Http::fake(['schwab.test/token' => Http::response('provider failure', 500)]);
        [$id, $state] = $this->startLink(User::factory()->onTrial()->create());
        $this->get("/auth/schwab/callback?state={$state}&code=code")->assertServerError();
        $this->getJson("/api/v1/link/{$id}/status")->assertOk()->assertJson(['status' => 'failed']);
    }

    public function test_declining_authorization_reaches_the_waiting_cli(): void
    {
        Http::fake();
        [$id, $state] = $this->startLink(User::factory()->onTrial()->create());
        $this->get("/auth/schwab/callback?state={$state}&error=access_denied")->assertForbidden();
        $this->getJson("/api/v1/link/{$id}/status")->assertOk()->assertJson(['status' => 'failed']);
        Http::assertNothingSent();
    }

    public function test_missing_link_session_does_not_fall_back_to_anonymous_linking(): void
    {
        Http::fake();
        [$id, $state] = $this->startLink(User::factory()->onTrial()->create());
        Cache::forget("link_session:{$id}");
        $this->get("/auth/schwab/callback?state={$state}&code=code")->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('trading_accounts', 0);
    }

    public function test_other_browser_tab_cannot_replace_the_link_owner(): void
    {
        $this->fakeSchwab();
        $owner = User::factory()->onTrial()->create();
        [$id, $state] = $this->startLink($owner);
        [$otherId] = $this->startLink(User::factory()->onTrial()->create());
        $this->withSession(['link_session_id' => $otherId])
            ->get("/auth/schwab/callback?state={$state}&code=code")->assertRedirect();
        $this->assertDatabaseHas('trading_accounts', ['user_id' => $owner->id]);
        $this->assertNotNull(Cache::get("link_session:{$otherId}"));
    }

    public function test_unfinished_attempt_cannot_show_success(): void
    {
        [$id] = $this->startLink(User::factory()->onTrial()->create());
        $this->get("/auth/link/complete?link_session_id={$id}")->assertForbidden();
    }

    public function test_link_started_before_deployment_can_complete(): void
    {
        $this->fakeSchwab();
        $owner = User::factory()->onTrial()->create();
        $id = \Illuminate\Support\Str::uuid()->toString();
        $state = bin2hex(random_bytes(16));
        Cache::put("link_session:{$id}", ['user_id' => $owner->id], now()->addMinutes(10));
        $returnUrl = route('auth.link.complete', ['link_session_id' => $id]);
        Cache::put("schwab_state:{$state}", $returnUrl, now()->addMinutes(10));

        $this->withSession(['link_session_id' => $id])
            ->get("/auth/schwab/callback?state={$state}&code=code")->assertRedirect($returnUrl);
        $this->get($returnUrl)->assertOk()->assertSee('Account linked');
    }

    public function test_compiled_routes_match_the_success_page(): void
    {
        $routes = app('router')->getRoutes()->compile();
        $compiled = new \Illuminate\Routing\CompiledRouteCollection($routes['compiled'], $routes['attributes']);
        $compiled->setRouter(app('router'))->setContainer(app());
        $route = $compiled->match(\Illuminate\Http\Request::create('/auth/link/complete'));
        $this->assertSame('auth.link.complete', $route->getName());
    }
}
