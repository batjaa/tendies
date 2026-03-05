<?php

namespace App\Http\Controllers;

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

        $path = '/accounts/' . $request->input('account_hash') . '/transactions';
        $query = [
            'startDate' => $request->input('start'),
            'endDate' => $request->input('end'),
        ];

        if ($request->has('types')) {
            $query['types'] = $request->input('types');
        }

        $accountHash = $request->input('account_hash');
        $start = $request->input('start');
        $end = $request->input('end');
        $types = $request->input('types', '');

        $cacheKey = "schwab_txns:{$request->user()->id}:{$accountHash}:{$start}:{$end}:{$types}";
        $todayDate = now()->format('Y-m-d');
        $coversToday = Carbon::parse($start)->format('Y-m-d') <= $todayDate
            && Carbon::parse($end)->format('Y-m-d') >= $todayDate;
        $ttl = $coversToday ? now()->addSeconds(30) : now()->addDays(7);

        $transactions = Cache::remember($cacheKey, $ttl, function () use ($schwab, $request, $path, $query) {
            return $schwab->makeRequest($request->user(), 'get', $path, $query);
        });

        return response()->json($transactions);
    }
}
