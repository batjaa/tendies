<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountUpgradeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Public endpoints (no auth required).
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/waitlist/signup', [WaitlistController::class, 'signup']);

Route::middleware(['auth:api', 'subscribed'])->group(function () {
    Route::get('/v1/accounts', [AccountController::class, 'index']);
    Route::get('/v1/transactions', [TransactionController::class, 'index']);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/v1/profile', [ProfileController::class, 'show']);
    Route::patch('/v1/profile', [ProfileController::class, 'update']);
    Route::get('/v1/me', [ProfileController::class, 'show']);
    Route::get('/v1/subscription', [SubscriptionController::class, 'status']);
    Route::post('/v1/subscription/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/v1/subscription/portal', [SubscriptionController::class, 'portal']);
    Route::post('/v1/link/initiate', [LinkController::class, 'initiate']);
    Route::post('/v1/account/upgrade', [AccountUpgradeController::class, 'upgrade']);
});
