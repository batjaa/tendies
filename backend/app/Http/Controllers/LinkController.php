<?php

namespace App\Http\Controllers;

use App\Services\LinkSessionService;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LinkController extends Controller
{
    public function initiate(Request $request, LinkSessionService $sessions)
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

        $sessionId = $sessions->create($user);

        return response()->json([
            'link_session_id' => $sessionId,
            'authorize_url' => config('app.url')."/auth/link/{$sessionId}",
        ]);
    }

    public function authorize(string $sessionId, SchwabService $schwab)
    {
        abort_unless(Cache::has("link_session:{$sessionId}"), 403, 'Invalid or expired link session');

        $state = bin2hex(random_bytes(16));
        Cache::put("schwab_state:{$state}", [
            'return_url' => route('auth.link.complete', ['link_session_id' => $sessionId]),
            'link_session_id' => $sessionId,
        ], now()->addMinutes(10));

        return redirect($schwab->getAuthorizeUrl($state));
    }

    public function status(Request $request, string $sessionId)
    {
        $result = Cache::get("link_result:{$sessionId}");
        abort_unless($result, 410, 'Link session expired. Run tendies account link again.');
        abort_unless($result['user_id'] === $request->user()->id, 404);

        return response()->json(['status' => $result['status']])->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request)
    {
        $request->validate(['link_session_id' => 'required|uuid']);
        $result = Cache::get('link_result:'.$request->query('link_session_id'));
        abort_unless(($result['status'] ?? null) === 'linked', 403, 'Invalid or expired link session');

        return response()->view('auth.link-complete')->header('Cache-Control', 'no-store');
    }
}
