<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::middleware(['auth:api', 'subscribed'])->group(function () {
    Route::get('/v1/accounts', [AccountController::class, 'index']);
    Route::get('/v1/transactions', [TransactionController::class, 'index']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/v1/subscription', [SubscriptionController::class, 'status']);
    Route::post('/v1/subscription/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/v1/subscription/portal', [SubscriptionController::class, 'portal']);
});
