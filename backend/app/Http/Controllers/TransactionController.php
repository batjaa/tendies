<?php

namespace App\Http\Controllers;

use App\Models\TradingAccountHash;
use App\Services\SchwabService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TransactionController extends Controller
{
    public function index(Request $request, SchwabService $schwab)
    {
        $request->validate([
            'account_hash' => 'required|string',
            'start' => 'required|date',
            'end' => 'required|date',
            'types' => 'sometimes|string',
        ]);

        $accountHash = $request->input('account_hash');

        // Resolve TradingAccount from the account hash, ensuring it belongs to the authenticated user.
        $hashEntry = TradingAccountHash::where('hash_value', $accountHash)
            ->whereHas('tradingAccount', fn ($q) => $q->where('user_id', $request->user()->id))
            ->first();

        if (! $hashEntry) {
            abort(403, 'Account not found or not owned by you');
        }

        $tradingAccount = $hashEntry->tradingAccount;

        $path = '/accounts/' . $accountHash . '/transactions';
        $query = [
            'startDate' => $request->input('start'),
            'endDate' => $request->input('end'),
        ];

        if ($request->has('types')) {
            $query['types'] = $request->input('types');
        }

        $start = $request->input('start');
        $end = $request->input('end');
        $types = $request->input('types', '');

        $cacheKey = "schwab_txns:{$tradingAccount->id}:{$accountHash}:{$start}:{$end}:{$types}";
        $todayDate = now()->format('Y-m-d');
        $coversToday = Carbon::parse($start)->format('Y-m-d') <= $todayDate
            && Carbon::parse($end)->format('Y-m-d') >= $todayDate;
        $ttl = $coversToday ? now()->addSeconds(30) : now()->addDays(7);

        $transactions = Cache::remember($cacheKey, $ttl, function () use ($schwab, $tradingAccount, $path, $query) {
            return $schwab->makeRequest($tradingAccount, 'get', $path, $query);
        });

        return response()->json($transactions);
    }
}
