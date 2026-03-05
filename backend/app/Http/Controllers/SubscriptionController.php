<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();

        if ($user->subscribed('default')) {
            $subscription = $user->subscription('default');
            $status = $subscription->pastDue() ? 'past_due' : 'active';

            return response()->json([
                'status' => $status,
                'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
                'subscription' => [
                    'plan' => $this->planName($subscription->stripe_price),
                    'ends_at' => $subscription->ends_at?->toIso8601String(),
                ],
            ]);
        }

        if ($user->onGenericTrial()) {
            return response()->json([
                'status' => 'trialing',
                'trial_ends_at' => $user->trial_ends_at->toIso8601String(),
            ]);
        }

        return response()->json([
            'status' => 'expired',
            'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'plan' => 'required|in:monthly,yearly',
        ]);

        $priceId = $request->plan === 'yearly'
            ? config('services.stripe.yearly_price_id')
            : config('services.stripe.monthly_price_id');

        $checkout = $request->user()
            ->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => config('app.url') . '/subscription/success',
                'cancel_url' => config('app.url') . '/subscription/cancel',
            ]);

        return response()->json(['checkout_url' => $checkout->url]);
    }

    public function portal(Request $request)
    {
        $url = $request->user()->billingPortalUrl(
            config('app.url') . '/subscription/success'
        );

        return response()->json(['portal_url' => $url]);
    }

    private function planName(?string $priceId): string
    {
        return match ($priceId) {
            config('services.stripe.yearly_price_id') => 'yearly',
            default => 'monthly',
        };
    }
}
