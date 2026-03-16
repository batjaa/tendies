<?php

use App\Services\SchwabService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            'rate-limit-cli' => \App\Http\Middleware\RateLimitCliQuery::class,
            'restrict-timeframe' => \App\Http\Middleware\RestrictTimeframe::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);

        // Auto-login from cache when returning to /oauth/authorize after Schwab callback.
        // This avoids depending on session cookies surviving the cross-site redirect.
        $middleware->appendToGroup('web', \App\Http\Middleware\AutoLoginFromCache::class);

        // Redirect authenticated users away from guest-only pages.
        $middleware->redirectUsersTo('/account');

        // Redirect unauthenticated web requests.
        // Passport /oauth/* paths → Schwab OAuth (CLI PKCE flow).
        // Everything else → web login page.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            // Passport authorize flow — redirect to Schwab OAuth.
            if (str_starts_with($request->path(), 'oauth/')) {
                $passportAuthorizeUrl = $request->fullUrl();
                $state = bin2hex(random_bytes(16));
                Cache::put("schwab_state:{$state}", $passportAuthorizeUrl, now()->addMinutes(10));

                return app(SchwabService::class)->getAuthorizeUrl($state);
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (\App\Exceptions\SchwabAuthException $e, Request $request) {
            return response()->json([
                'error' => 'schwab_token_expired',
                'message' => 'Schwab session expired. Run `tendies auth login` to re-authenticate.',
            ], 401);
        });
    })->create();
