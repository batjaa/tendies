<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SchwabCallbackController extends Controller
{
    public function callback(Request $request, SchwabService $schwab)
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

        // Derive a stable identity from Schwab account hashes (survives token rotation).
        $accountHash = $schwab->fetchAccountHash($tokenData['access_token']);

        // Look up by stable account hash first.
        $user = User::where('schwab_account_hash', $accountHash)->first();

        if (! $user) {
            // Adopt a legacy user (created before account-hash identity) if one exists.
            // Legacy users have @schwab.local emails and null schwab_account_hash.
            $user = User::whereNull('schwab_account_hash')
                ->where('email', 'like', '%@schwab.local')
                ->whereHas('schwabToken')
                ->latest('id')
                ->first();

            if ($user) {
                // Claim this legacy user with the stable account hash.
                $user->update(['schwab_account_hash' => $accountHash]);
            } else {
                $user = User::create([
                    'name' => 'Schwab User',
                    'email' => $accountHash . '@schwab.local',
                    'password' => bcrypt(Str::random(32)),
                    'schwab_account_hash' => $accountHash,
                ]);
            }
        }

        if (! $user->trial_ends_at && ! $user->subscribed('default')) {
            $user->trial_ends_at = now()->addDays(7);
            $user->save();
        }

        $schwab->storeTokens($user, $tokenData);

        Auth::login($user);

        // Extract the Passport state from the original authorize URL and cache the user ID.
        // This allows AutoLoginFromCache middleware to re-authenticate the user
        // even if the session cookie is lost (e.g., through ngrok).
        $parsedUrl = parse_url($passportAuthorizeUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        if (! empty($queryParams['state'])) {
            Cache::put("passport_user:{$queryParams['state']}", $user->id, now()->addMinutes(5));
        }

        // Redirect back to the Passport authorize URL that started this flow.
        return redirect($passportAuthorizeUrl);
    }
}
