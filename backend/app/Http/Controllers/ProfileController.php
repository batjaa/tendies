<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user->toAuthArray(),
            'linked_accounts' => $user->tradingAccounts()->count(),
            'subscription' => $user->subscriptionSummary(),
            'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $request->user()->update($validated);

        return $this->show($request);
    }
}
