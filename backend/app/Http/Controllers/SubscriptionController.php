<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();

        if ($user->subscribed('default')) {
            $summary = $user->subscriptionSummary();

            return response()->json([
                'status' => $summary['status'],
                'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
                'subscription' => array_merge($summary, [
                    'ends_at' => $user->subscription('default')->ends_at?->toIso8601String(),
                ]),
            ]);
        }

        if ($user->hasProGrant()) {
            return response()->json([
                'status' => 'active',
                'pro_until' => $user->pro_until->toIso8601String(),
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

}
