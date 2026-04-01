<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->tier() !== 'free') {
            return $next($request);
        }

        return response()->json([
            'error' => 'subscription_required',
            'message' => 'Your trial has expired. Subscribe to continue using Tendies Pro.',
        ], 403);
    }
}
