<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Tests\Traits\CreatesSubscribedUser;

class SubscriptionStatusTest extends TestCase
{
    use CreatesSubscribedUser, RefreshDatabase;

    public function test_trialing_returns_trialing_status(): void
    {
        $user = User::factory()->onTrial()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJson(['status' => 'trialing']);
    }

    public function test_active_subscription_returns_active_status(): void
    {
        $user = $this->createSubscribedUser('active', 'price_monthly_test');
        config(['services.stripe.monthly_price_id' => 'price_monthly_test']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJson([
                'status' => 'active',
                'subscription' => ['plan' => 'monthly'],
            ]);
    }

    public function test_past_due_returns_expired_status(): void
    {
        // Cashier v16 deactivates past_due subscriptions by default,
        // so they are not considered subscribed and fall through to expired.
        $user = $this->createSubscribedUser('past_due');
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJson(['status' => 'expired']);
    }

    public function test_expired_trial_returns_expired_status(): void
    {
        $user = User::factory()->trialExpired()->create();
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJson(['status' => 'expired']);
    }

    public function test_yearly_plan_name_resolution(): void
    {
        config(['services.stripe.yearly_price_id' => 'price_yearly_test']);
        $user = $this->createSubscribedUser('active', 'price_yearly_test');
        Passport::actingAs($user);

        $response = $this->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJson([
                'status' => 'active',
                'subscription' => ['plan' => 'yearly'],
            ]);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/subscription');

        $response->assertUnauthorized();
    }
}
