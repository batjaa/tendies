<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RateLimitCliQuery
{
    /**
     * Rate limit free-tier CLI queries by X-Query-ID header.
     *
     * Flow:
     *   request → free user? → has X-Query-ID? → new query ID today?
     *     │          │              │                   │
     *     │ no       │ yes          │ no → 400          │ no (retry) → pass
     *     │          │              │                   │
     *     │          │              │           under limit? → count + pass
     *     └─ pass    └──────────────┘              │
     *                                              │ no → 429
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->tier() !== 'free') {
            return $next($request);
        }

        $queryId = $request->header('X-Query-ID');
        if (! $queryId) {
            return response()->json(['error' => 'missing_query_id'], 400);
        }

        // Use client timezone for day boundary, validated against PHP timezone list.
        $tz = $request->header('X-Timezone', 'UTC');
        if (! in_array($tz, timezone_identifiers_list())) {
            $tz = 'UTC';
        }
        $today = now($tz)->toDateString();

        $cacheKey = "cli_query:{$user->id}:{$queryId}";
        $dailyKey = "cli_daily:{$user->id}:{$today}";

        // Single Cache::get instead of has() + get() — avoids extra round-trip.
        $seenBefore = Cache::get($cacheKey);
        $currentCount = (int) Cache::get($dailyKey, 0);

        if (! $seenBefore) {
            if ($currentCount >= User::FREE_DAILY_LIMIT) {
                return response()->json([
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Free tier limit: ' . User::FREE_DAILY_LIMIT . ' queries/day. Upgrade at mytendies.app/pricing',
                    'remaining' => 0,
                ], 429);
            }

            $currentCount++;
            Cache::put($dailyKey, $currentCount, now($tz)->endOfDay());
            Cache::put($cacheKey, true, now($tz)->endOfDay());
        }

        $remaining = max(0, User::FREE_DAILY_LIMIT - $currentCount);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
