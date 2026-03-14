<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if (nova_get_setting('waitlist_mode')) {
            return response()->json([
                'error' => 'waitlist_active',
                'message' => 'Registration is currently invite-only. Join the waitlist at mytendies.app.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create($validated);
        $token = $user->createToken('cli')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['error' => 'invalid_credentials', 'message' => 'Invalid email or password.'], 401);
        }

        $user = Auth::user();

        // Revoke all existing tokens (PKCE + PAT) and issue fresh PAT.
        $user->tokens()->delete();
        $token = $user->createToken('cli')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ]);
    }
}
