<?php

namespace App\Http\Controllers;

use App\Services\SchwabService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request, SchwabService $schwab)
    {
        $tradingAccount = $request->user()->primaryTradingAccount;

        if (! $tradingAccount) {
            return response()->json([], 200);
        }

        $accounts = $schwab->makeRequest(
            $tradingAccount,
            'get',
            '/accounts/accountNumbers'
        );

        return response()->json($accounts);
    }
}
