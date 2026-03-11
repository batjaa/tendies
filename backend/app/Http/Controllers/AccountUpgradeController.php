<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountUpgradeController extends Controller
{
    public function upgrade(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAnonymous()) {
            return response()->json([
                'error' => 'already_registered',
                'message' => 'This account already has an email and password.',
            ], 409);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update($validated);

        // Revoke all existing tokens (PKCE) and issue fresh PAT.
        $user->tokens()->delete();
        $token = $user->createToken('cli')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ]);
    }
}
