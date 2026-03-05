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
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);

        // Auto-login from cache when returning to /oauth/authorize after Schwab callback.
        // This avoids depending on session cookies surviving the cross-site redirect.
        $middleware->appendToGroup('web', \App\Http\Middleware\AutoLoginFromCache::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            $passportAuthorizeUrl = $request->fullUrl();

            $state = bin2hex(random_bytes(16));

            // Store in cache (database-backed) instead of session — survives cross-site redirects.
            Cache::put("schwab_state:{$state}", $passportAuthorizeUrl, now()->addMinutes(10));

            return app(SchwabService::class)->getAuthorizeUrl($state);
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
