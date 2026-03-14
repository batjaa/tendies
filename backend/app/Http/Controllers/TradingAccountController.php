<?php

namespace App\Http\Controllers;

use App\Models\TradingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $this->authorize('update', $tradingAccount);

        $validated = $request->validate([
            'display_name' => 'required|string|max:255',
        ]);

        $tradingAccount->update($validated);

        return response()->json($tradingAccount);
    }

    public function destroy(Request $request, TradingAccount $tradingAccount): JsonResponse
    {
        $this->authorize('delete', $tradingAccount);

        $user = $request->user();

        return DB::transaction(function () use ($user, $tradingAccount) {
            $count = $user->tradingAccounts()->lockForUpdate()->count();

            if ($count <= 1) {
                return response()->json([
                    'error' => 'last_account',
                    'message' => 'Cannot unlink your only trading account.',
                ], 409);
            }

            $wasPrimary = $tradingAccount->is_primary;
            $tradingAccount->delete();

            if ($wasPrimary) {
                $user->tradingAccounts()->oldest()->first()?->update(['is_primary' => true]);
            }

            return response()->json(['message' => 'Account unlinked.']);
        });
    }

    public function setPrimary(Request $request, TradingAccount $tradingAccount): JsonResponse
    {
        $this->authorize('setPrimary', $tradingAccount);

        DB::transaction(function () use ($request, $tradingAccount) {
            $request->user()->tradingAccounts()->update(['is_primary' => false]);
            $tradingAccount->update(['is_primary' => true]);
        });

        return response()->json($tradingAccount->refresh());
    }
}
