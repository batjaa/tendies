<?php

namespace App\Http\Controllers;

use App\Services\SchwabService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request, SchwabService $schwab)
    {
        $accounts = $schwab->makeRequest(
            $request->user(),
            'get',
            '/accounts/accountNumbers'
        );

        return response()->json($accounts);
    }
}
