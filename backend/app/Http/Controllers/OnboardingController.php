<?php

namespace App\Http\Controllers;

use App\Services\SchwabService;

class OnboardingController extends Controller
{
    public function showConnect()
    {
        return view('onboarding.connect');
    }

    public function connectSchwab(SchwabService $schwab)
    {
        $returnUrl = route('onboarding.complete');

        return $schwab->redirectToAuthorize(auth()->user(), $returnUrl);
    }

    public function complete()
    {
        $user = auth()->user();
        $user->load('primaryTradingAccount.hashes');

        return view('onboarding.complete', [
            'user' => $user,
            'tradingAccount' => $user->primaryTradingAccount,
        ]);
    }
}
