<?php

namespace App\Http\Controllers;

use App\Services\SchwabService;
use Illuminate\Http\Request;

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

        $transactions = $schwab->makeRequest(
            $request->user(),
            'get',
            $path,
            $query
        );

        return response()->json($transactions);
    }
}
