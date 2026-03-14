<?php

use App\Http\Controllers\SchwabCallbackController;
use App\Services\SchwabService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome', [
        'waitlistMode' => (bool) nova_get_setting('waitlist_mode'),
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

Route::get('/auth/waitlist/verify', function (\Illuminate\Http\Request $request, \App\Services\SchwabService $schwab) {
    $token = $request->query('token');
    if (! $token) {
        abort(400, 'Missing invite token');
    }

    $entry = \App\Models\WaitlistEntry::where('invite_token', $token)
        ->where('status', 'invited')
        ->first();

    if (! $entry) {
        abort(403, 'Invalid or already used invite');
    }

    if ($entry->isExpired()) {
        abort(403, 'Invite has expired');
    }

    // Store token in session for the Schwab callback to consume.
    session(['waitlist_invite_token' => $token]);

    $state = bin2hex(random_bytes(16));

    $passportAuthorizeUrl = config('app.url') . '/auth/waitlist/welcome';
    Cache::put("schwab_state:{$state}", $passportAuthorizeUrl, now()->addMinutes(10));

    return redirect($schwab->getAuthorizeUrl($state));
})->name('auth.waitlist.verify');

Route::get('/subscription/success', function () {
    return view('subscription.success');
});

Route::get('/subscription/cancel', function () {
    return view('subscription.cancel');
});
