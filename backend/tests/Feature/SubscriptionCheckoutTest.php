<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_validates_plan_required(): void
    {
        Passport::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/subscription/checkout');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_rejects_invalid_plan(): void
    {
        Passport::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'lifetime']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
    }

    public function test_monthly_checkout_returns_stripe_url(): void
    {
        config(['services.stripe.monthly_price_id' => 'price_monthly_test']);

        $user = Mockery::mock(User::factory()->create())->makePartial();

        $subscriptionBuilder = Mockery::mock(SubscriptionBuilder::class);
        $user->shouldReceive('newSubscription')
            ->with('default', 'price_monthly_test')
            ->andReturn($subscriptionBuilder);

        $checkout = Mockery::mock();
        $checkout->url = 'https://checkout.stripe.com/session-monthly';
        $subscriptionBuilder->shouldReceive('checkout')->andReturn($checkout);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'monthly']);

        $response->assertOk()
            ->assertJson(['checkout_url' => 'https://checkout.stripe.com/session-monthly']);
    }

    public function test_yearly_checkout_returns_stripe_url(): void
    {
        config(['services.stripe.yearly_price_id' => 'price_yearly_test']);

        $user = Mockery::mock(User::factory()->create())->makePartial();

        $subscriptionBuilder = Mockery::mock(SubscriptionBuilder::class);
        $user->shouldReceive('newSubscription')
            ->with('default', 'price_yearly_test')
            ->andReturn($subscriptionBuilder);

        $checkout = Mockery::mock();
        $checkout->url = 'https://checkout.stripe.com/session-yearly';
        $subscriptionBuilder->shouldReceive('checkout')->andReturn($checkout);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'yearly']);

        $response->assertOk()
            ->assertJson(['checkout_url' => 'https://checkout.stripe.com/session-yearly']);
    }

    public function test_portal_returns_portal_url(): void
    {
        $user = Mockery::mock(User::factory()->create())->makePartial();
        $user->shouldReceive('billingPortalUrl')->andReturn('https://billing.stripe.com/portal');

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/subscription/portal');

        $response->assertOk()
            ->assertJson(['portal_url' => 'https://billing.stripe.com/portal']);
    }

    public function test_checkout_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/subscription/checkout', ['plan' => 'monthly']);

        $response->assertUnauthorized();
    }

    public function test_portal_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/subscription/portal');

        $response->assertUnauthorized();
    }
}
