<?php

namespace App\Services;

use App\Exceptions\SchwabAuthException;
use App\Models\SchwabToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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

    public function storeTokens(User $user, array $tokenData): void
    {
        $user->schwabToken()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'encrypted_access_token' => $tokenData['access_token'],
                'encrypted_refresh_token' => $tokenData['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 1800),
            ]
        );
    }

    public function getValidAccessToken(User $user): string
    {
        $schwabToken = $user->schwabToken;

        if (! $schwabToken) {
            throw new SchwabAuthException('No Schwab token found for user');
        }

        if ($schwabToken->token_expires_at && $schwabToken->token_expires_at->isFuture()) {
            return $schwabToken->encrypted_access_token;
        }

        $tokenData = $this->refreshToken($schwabToken->encrypted_refresh_token);
        $this->storeTokens($user, $tokenData);

        return $tokenData['access_token'];
    }

    public function makeRequest(User $user, string $method, string $path, array $query = []): array
    {
        $accessToken = $this->getValidAccessToken($user);
        $url = config('schwab.api_base_url') . $path;

        $response = Http::withToken($accessToken)
            ->$method($url, $query);

        if (! $response->successful()) {
            report("Schwab API error {$response->status()}: {$response->body()}");
            throw new \RuntimeException("Schwab API request failed (HTTP {$response->status()})");
        }

        return $response->json();
    }
}
