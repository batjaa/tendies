<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/v1/accounts', [AccountController::class, 'index']);
    Route::get('/v1/transactions', [TransactionController::class, 'index']);
});
