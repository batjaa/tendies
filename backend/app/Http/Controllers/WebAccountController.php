<?php

namespace App\Http\Controllers;

use App\Models\TradingAccount;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class WebAccountController extends Controller
{
    public function show()
    {
        $user = auth()->user();
        $user->load('tradingAccounts.hashes', 'subscriptions');

        return view('account.show', [
            'user' => $user,
            'tier' => $user->tier(),
            'subscription' => $user->subscriptionSummary(),
        ]);
    }

    public function showPasswordForm()
    {
        return view('account.password');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => $request->password,
        ]);

        return back()->with('success', 'Password updated successfully.');
    }

    public function disconnectBrokerage(TradingAccount $tradingAccount)
    {
        if ($tradingAccount->user_id !== auth()->id()) {
            abort(403);
        }

        $tradingAccount->schwabToken()->delete();
        $tradingAccount->hashes()->delete();
        $tradingAccount->delete();

        return back()->with('success', 'Brokerage disconnected.');
    }

    public function connectSchwab(SchwabService $schwab)
    {
        return $schwab->redirectToAuthorize(auth()->user(), route('account.show'));
    }

    public function billing(Request $request)
    {
        $url = $request->user()->billingPortalUrl(route('account.show'));

        return redirect($url);
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
                'success_url' => route('account.show') . '?subscribed=1',
                'cancel_url' => route('account.show'),
            ]);

        return redirect($checkout->url);
    }
}
