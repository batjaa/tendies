<?php

namespace App\Http\Controllers;

use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradingAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->tradingAccounts()
            ->select('id', 'provider', 'display_name', 'is_primary', 'last_synced_at', 'created_at')
            ->get();

        return response()->json($accounts);
    }

    public function update(Request $request, TradingAccount $tradingAccount): JsonResponse
    {
        if ($tradingAccount->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'display_name' => 'required|string|max:255',
        ]);

        $tradingAccount->update($validated);

        return response()->json($tradingAccount);
    }

    public function destroy(Request $request, TradingAccount $tradingAccount): JsonResponse
    {
        if ($tradingAccount->user_id !== $request->user()->id) {
            abort(403);
        }

        $user = $request->user();

        if ($user->tradingAccounts()->count() <= 1) {
            return response()->json([
                'error' => 'last_account',
                'message' => 'Cannot unlink your only trading account.',
            ], 409);
        }

        $wasPrimary = $tradingAccount->is_primary;
        $tradingAccount->delete(); // Cascades to schwab_token + hashes via FK

        if ($wasPrimary) {
            $user->tradingAccounts()->oldest()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Account unlinked.']);
    }

    public function setPrimary(Request $request, TradingAccount $tradingAccount): JsonResponse
    {
        if ($tradingAccount->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->user()->tradingAccounts()->update(['is_primary' => false]);
        $tradingAccount->update(['is_primary' => true]);

        return response()->json($tradingAccount);
    }
}
