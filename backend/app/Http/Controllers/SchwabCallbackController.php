<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeMail;
use App\Services\LinkAccountService;
use App\Services\LinkSessionService;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SchwabCallbackController extends Controller
{
    public function callback(Request $request, SchwabService $schwab, LinkAccountService $linkService, LinkSessionService $sessions)
    {
        $state = $request->input('state');
        if (! $state || ! preg_match('/^[a-f0-9]{32}$/', $state)) {
            abort(400, 'Missing or malformed state parameter');
        }

        // Retrieve and delete state from cache (one-time use).
        $stateData = Cache::pull("schwab_state:{$state}");
        if (! $stateData) {
            abort(403, 'Invalid or expired OAuth state');
        }

        // Read old string states too, so deployments do not interrupt in-flight OAuth.
        $passportAuthorizeUrl = is_array($stateData) ? $stateData['return_url'] : $stateData;
        $linkSessionId = is_array($stateData)
            ? ($stateData['link_session_id'] ?? null)
            : $request->session()->get('link_session_id');

        // Validate redirect is to our own app (prevent open redirect).
        $parsedRedirect = parse_url($passportAuthorizeUrl);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        if (($parsedRedirect['host'] ?? '') !== $appHost) {
            abort(400, 'Invalid redirect URL');
        }

        try {
            // Resolve ownership before contacting Schwab. Expired links must never
            // fall back to creating or signing in an unrelated anonymous user.
            $authenticatedUser = null;
            if ($linkSessionId) {
                $authenticatedUser = $sessions->consume($linkSessionId);
                abort_unless($authenticatedUser, 403, 'Invalid or expired link session');
                if ($request->session()->get('link_session_id') === $linkSessionId) {
                    $request->session()->forget('link_session_id');
                }
            }

            abort_if($request->has('error'), 403, 'Schwab authorization was not completed');
            $code = $request->input('code');
            abort_unless($code, 400, 'Missing authorization code');

            $tokenData = $schwab->exchangeCode($code);
            $hashes = $schwab->fetchAccountHashes($tokenData['access_token']);
            $result = $linkService->resolveOrCreateAccount($hashes, $tokenData, $authenticatedUser);
        } catch (\Throwable $error) {
            $sessions->finish($linkSessionId, false);
            throw $error;
        }

        $sessions->finish($linkSessionId, true);
        $user = $result['user'];

        if ($result['is_new_account'] && $user->email && $user->tradingAccounts()->count() === 1) {
            Mail::to($user)->queue(new WelcomeMail($user));
        }

        Auth::guard('web')->login($user);

        // Cache user ID for AutoLoginFromCache middleware (survives session loss through ngrok).
        $parsedUrl = parse_url($passportAuthorizeUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        if (! empty($queryParams['state'])) {
            Cache::put("passport_user:{$queryParams['state']}", $user->id, now()->addMinutes(5));
        }

        return redirect($passportAuthorizeUrl);
    }
}
