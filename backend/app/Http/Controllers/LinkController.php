<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LinkController extends Controller
{
    public function initiate(Request $request)
    {
        $request->validate([
            'provider' => 'required|string|in:schwab',
        ]);

        $user = $request->user();

        if (! $user->canLinkMoreAccounts()) {
            return response()->json([
                'error' => 'account_limit_reached',
                'message' => 'Free tier allows one provider connection. Upgrade to link more.',
            ], 403);
        }

        $sessionId = Str::uuid()->toString();

        Cache::put("link_session:{$sessionId}", [
            'user_id' => $user->id,
            'provider' => $request->input('provider'),
        ], now()->addMinutes(10));

        return response()->json([
            'link_session_id' => $sessionId,
            'authorize_url' => config('app.url') . "/auth/link/{$sessionId}",
        ]);
    }
}
