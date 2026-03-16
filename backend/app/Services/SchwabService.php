<?php

namespace App\Services;

use App\Exceptions\SchwabAuthException;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SchwabService
{
    public function getAuthorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('schwab.client_id'),
            'redirect_uri' => config('schwab.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return config('schwab.authorize_url') . '?' . $params;
    }

    /**
     * Create a link session and redirect the user to Schwab OAuth.
     * After Schwab callback, the user will be redirected to $returnUrl.
     */
    public function redirectToAuthorize(User $user, string $returnUrl): RedirectResponse
    {
        $sessionId = Str::uuid()->toString();

        Cache::put("link_session:{$sessionId}", ['user_id' => $user->id], now()->addMinutes(10));
        session(['link_session_id' => $sessionId]);

        $state = bin2hex(random_bytes(16));
        Cache::put("schwab_state:{$state}", $returnUrl, now()->addMinutes(10));

        return redirect($this->getAuthorizeUrl($state));
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()
            ->withBasicAuth(config('schwab.client_id'), config('schwab.client_secret'))
            ->post(config('schwab.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('schwab.redirect_uri'),
            ]);

        if (! $response->successful()) {
            report('Schwab token exchange failed: ' . $response->body());
            throw new \RuntimeException('Schwab token exchange failed (HTTP ' . $response->status() . ')');
        }

        return $response->json();
    }

    public function refreshToken(string $refreshToken): array
    {
        $response = Http::asForm()
            ->withBasicAuth(config('schwab.client_id'), config('schwab.client_secret'))
            ->post(config('schwab.token_url'), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->successful()) {
            report('Schwab token refresh failed: ' . $response->body());
            throw new SchwabAuthException('Schwab token refresh failed (HTTP ' . $response->status() . ')');
        }

        return $response->json();
    }

    public function storeTokens(TradingAccount $tradingAccount, array $tokenData): void
    {
        $tradingAccount->schwabToken()->updateOrCreate(
            ['trading_account_id' => $tradingAccount->id],
            [
                'encrypted_access_token' => $tokenData['access_token'],
                'encrypted_refresh_token' => $tokenData['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 1800),
            ]
        );
    }

    /**
     * Get a valid access token for a trading account, refreshing if expired.
     *
     * Uses a cache lock to prevent race conditions when multiple requests
     * try to refresh the same expired token simultaneously (e.g., the
     * menubar app firing day + week queries in parallel).
     *
     * Lock flow:
     *   Request A arrives, token expired → acquires lock → refreshes → releases
     *   Request B arrives during refresh → blocks on lock → re-reads → already fresh
     */
    public function getValidAccessToken(TradingAccount $tradingAccount): string
    {
        $schwabToken = $tradingAccount->schwabToken;

        if (! $schwabToken) {
            throw new SchwabAuthException('No Schwab token found for trading account');
        }

        if ($schwabToken->token_expires_at && $schwabToken->token_expires_at->isFuture()) {
            return $schwabToken->encrypted_access_token;
        }

        $lock = Cache::lock("schwab_refresh:{$tradingAccount->id}", 10);

        try {
            $lock->block(5);
            $tradingAccount->schwabToken->refresh();

            if ($tradingAccount->schwabToken->token_expires_at->isFuture()) {
                return $tradingAccount->schwabToken->encrypted_access_token;
            }

            $tokenData = $this->refreshToken($tradingAccount->schwabToken->encrypted_refresh_token);
            $this->storeTokens($tradingAccount, $tokenData);

            return $tokenData['access_token'];
        } finally {
            optional($lock)->release();
        }
    }

    public function makeRequest(TradingAccount $tradingAccount, string $method, string $path, array $query = []): array
    {
        $accessToken = $this->getValidAccessToken($tradingAccount);
        $url = config('schwab.api_base_url') . $path;

        $response = Http::withToken($accessToken)
            ->$method($url, $query);

        // On 401, refresh the token once and retry before giving up.
        if ($response->status() === 401) {
            $schwabToken = $tradingAccount->schwabToken;
            if ($schwabToken) {
                $tokenData = $this->refreshToken($schwabToken->encrypted_refresh_token);
                $this->storeTokens($tradingAccount, $tokenData);
                $response = Http::withToken($tokenData['access_token'])
                    ->$method($url, $query);
            }
        }

        if (! $response->successful()) {
            report("Schwab API error {$response->status()}: {$response->body()}");

            if ($response->status() === 401) {
                throw new SchwabAuthException("Schwab API request failed (HTTP 401)");
            }

            throw new \RuntimeException("Schwab API request failed (HTTP {$response->status()})");
        }

        return $response->json();
    }

    /**
     * Fetch individual account hash values from Schwab using a raw access token.
     * Returns an array of hashValue strings (not a composite hash).
     */
    public function fetchAccountHashes(string $accessToken): array
    {
        $url = config('schwab.api_base_url') . '/accounts/accountNumbers';

        $response = Http::withToken($accessToken)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch Schwab account numbers (HTTP ' . $response->status() . ')');
        }

        $accounts = $response->json();
        $hashes = collect($accounts)->pluck('hashValue')->sort()->values()->all();

        if (empty($hashes)) {
            throw new \RuntimeException('No Schwab accounts found');
        }

        return $hashes;
    }
}
