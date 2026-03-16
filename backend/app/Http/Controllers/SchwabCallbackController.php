<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeMail;
use App\Services\LinkAccountService;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SchwabCallbackController extends Controller
{
    public function callback(Request $request, SchwabService $schwab, LinkAccountService $linkService)
    {
        $state = $request->input('state');
        if (! $state || ! preg_match('/^[a-f0-9]{32}$/', $state)) {
            abort(400, 'Missing or malformed state parameter');
        }

        // Retrieve and delete state from cache (one-time use).
        $passportAuthorizeUrl = Cache::pull("schwab_state:{$state}");
        if (! $passportAuthorizeUrl) {
            abort(403, 'Invalid or expired OAuth state');
        }

        // Validate redirect is to our own app (prevent open redirect).
        $parsedRedirect = parse_url($passportAuthorizeUrl);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        if (($parsedRedirect['host'] ?? '') !== $appHost) {
            abort(400, 'Invalid redirect URL');
        }

        $code = $request->input('code');
        if (! $code) {
            abort(400, 'Missing authorization code');
        }

        $tokenData = $schwab->exchangeCode($code);
        $hashes = $schwab->fetchAccountHashes($tokenData['access_token']);

        // Check for link session (authenticated user linking a new provider).
        $linkSessionId = $request->session()->get('link_session_id');
        $authenticatedUser = null;
        if ($linkSessionId) {
            $linkData = Cache::pull("link_session:{$linkSessionId}");
            if ($linkData) {
                $authenticatedUser = \App\Models\User::find($linkData['user_id']);
            }
            $request->session()->forget('link_session_id');
        }

        // Waitlist acceptance handled by WaitlistRegistrationController.

        $result = $linkService->resolveOrCreateAccount(
            $hashes,
            $tokenData,
            $authenticatedUser,
        );

        $user = $result['user'];

        if ($result['is_new_account'] && $user->email) {
            Mail::to($user)->queue(new WelcomeMail($user));
        }

        Auth::login($user);

        // Cache user ID for AutoLoginFromCache middleware (survives session loss through ngrok).
        $parsedUrl = parse_url($passportAuthorizeUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        if (! empty($queryParams['state'])) {
            Cache::put("passport_user:{$queryParams['state']}", $user->id, now()->addMinutes(5));
        }

        return redirect($passportAuthorizeUrl);
    }
}
