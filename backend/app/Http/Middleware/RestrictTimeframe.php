<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class RestrictTimeframe
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->tier() !== 'free') {
            return $next($request);
        }

        $start = $request->query('start');
        $end = $request->query('end');

        // Only restrict if both start and end are provided (transaction endpoint).
        if (! $start || ! $end) {
            return $next($request);
        }

        $daysDiff = Carbon::parse($start)->diffInDays(Carbon::parse($end));

        if ($daysDiff > User::FREE_MAX_DAYS) {
            return response()->json([
                'error' => 'timeframe_restricted',
                'message' => 'Free tier supports day and week queries. Upgrade for month/year.',
                'max_days' => User::FREE_MAX_DAYS,
            ], 403);
        }

        return $next($request);
    }
}
