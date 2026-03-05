<?php

namespace Tests\Traits;

use App\Models\User;
use Illuminate\Support\Str;

trait CreatesSubscribedUser
{
    protected function createSubscribedUser(string $stripeStatus = 'active', ?string $priceId = null): User
    {
        $user = User::factory()->withSchwabHash()->create();

        $this->addSubscription($user, $stripeStatus, $priceId);

        return $user;
    }

    protected function addSubscription(User $user, string $stripeStatus = 'active', ?string $priceId = null): void
    {
        $priceId ??= config('services.stripe.monthly_price_id') ?: 'price_monthly_test';
        $subscriptionId = 'sub_' . Str::random(14);
        $itemId = 'si_' . Str::random(14);

        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => $stripeStatus,
            'stripe_price' => $priceId,
        ]);

        $subscription->items()->create([
            'stripe_id' => $itemId,
            'stripe_product' => 'prod_test',
            'stripe_price' => $priceId,
            'quantity' => 1,
        ]);
    }
}
