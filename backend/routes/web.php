<?php

use App\Http\Controllers\SchwabCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/schwab/callback', [SchwabCallbackController::class, 'callback'])
    ->name('schwab.callback');

Route::get('/subscription/success', function () {
    return view('subscription.success');
});

Route::get('/subscription/cancel', function () {
    return view('subscription.cancel');
});
