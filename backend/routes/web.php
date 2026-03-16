<?php

use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SchwabCallbackController;
use App\Http\Controllers\WaitlistRegistrationController;
use App\Services\SchwabService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $waitlistMode = (bool) nova_get_setting('waitlist_mode');

    return view('welcome', [
        'waitlistMode' => $waitlistMode,
        'waitlistCount' => $waitlistMode ? \App\Models\WaitlistEntry::where('status', 'pending')->count() : 0,
    ]);
});

Route::get('/auth/schwab/callback', [SchwabCallbackController::class, 'callback'])
    ->name('schwab.callback');

Route::get('/auth/link/{sessionId}', function (string $sessionId, SchwabService $schwab) {
    $linkData = Cache::get("link_session:{$sessionId}");

    if (! $linkData) {
        abort(403, 'Invalid or expired link session');
    }

    // Store session ID for the callback to consume.
    session(['link_session_id' => $sessionId]);

    $state = bin2hex(random_bytes(16));

    // Build a Passport-style authorize URL to redirect back to after Schwab callback.
    $passportAuthorizeUrl = config('app.url') . '/auth/link/complete?' . http_build_query([
        'link_session_id' => $sessionId,
    ]);

    Cache::put("schwab_state:{$state}", $passportAuthorizeUrl, now()->addMinutes(10));

    return redirect($schwab->getAuthorizeUrl($state));
})->name('auth.link');

Route::get('/auth/link/complete', function () {
    return response()->json(['status' => 'linked', 'message' => 'Account linked successfully.']);
})->name('auth.link.complete');

Route::get('/auth/waitlist/verify', function (\Illuminate\Http\Request $request) {
    $token = $request->query('token');
    if (! $token) {
        abort(400, 'Missing invite token');
    }

    $entry = \App\Models\WaitlistEntry::findValidByToken($token);

    if (! $entry) {
        abort(403, 'Invalid or expired invite');
    }

    session(['waitlist_invite_token' => $token]);

    return redirect('/auth/waitlist/register');
})->name('auth.waitlist.verify');

Route::get('/auth/waitlist/register', [WaitlistRegistrationController::class, 'showForm'])
    ->name('auth.waitlist.register');
Route::post('/auth/waitlist/register', [WaitlistRegistrationController::class, 'register']);

Route::middleware('auth')->group(function () {
    Route::get('/onboarding/connect', [OnboardingController::class, 'showConnect'])
        ->name('onboarding.connect');
    Route::get('/onboarding/connect/schwab', [OnboardingController::class, 'connectSchwab'])
        ->name('onboarding.connect.schwab');
    Route::get('/onboarding/complete', [OnboardingController::class, 'complete'])
        ->name('onboarding.complete');
});

Route::get('/subscription/success', function () {
    return view('subscription.success');
});

Route::get('/subscription/cancel', function () {
    return view('subscription.cancel');
});
