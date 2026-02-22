<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AutoLoginFromCache
{
    public function handle(Request $request, Closure $next)
    {
        // If the user is already authenticated, skip.
        if (Auth::check()) {
            return $next($request);
        }

        // Check if this is a Passport authorize request with a state we've cached a user for.
        $state = $request->query('state');
        if ($state && ($userId = Cache::pull("passport_user:{$state}"))) {
            Auth::loginUsingId($userId);
        }

        return $next($request);
    }
}
