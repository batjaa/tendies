<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WaitlistRegistrationController extends Controller
{
    public function showForm(Request $request)
    {
        $entry = $this->resolveEntry($request);

        if (! $entry) {
            abort(403, 'Invalid or expired invite');
        }

        return view('waitlist.register', [
            'email' => $entry->email,
            'name' => $entry->name,
            'token' => $entry->invite_token,
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'name' => 'nullable|string|max:255',
            'password' => 'required|string|min:8|confirmed',
            'waitlist_invite_token' => 'required|string',
        ]);

        $entry = WaitlistEntry::findValidByToken($validated['waitlist_invite_token']);

        if (! $entry) {
            abort(403, 'Invalid or expired invite');
        }

        $user = DB::transaction(function () use ($validated, $entry) {
            $user = User::create([
                'name' => $validated['name'] ?? 'Tendies User',
                'email' => $validated['email'],
                'password' => $validated['password'],
                'trial_ends_at' => now()->addDays(7),
            ]);

            $entryUpdate = ['status' => 'accepted', 'accepted_at' => now()];
            if ($entry->email !== $validated['email']) {
                $entryUpdate['email'] = $validated['email'];
            }
            $entry->update($entryUpdate);

            return $user;
        });

        Auth::login($user);
        $request->session()->forget('waitlist_invite_token');

        return redirect('/onboarding/connect');
    }

    private function resolveEntry(Request $request): ?WaitlistEntry
    {
        $token = $request->session()->get('waitlist_invite_token');

        if (! $token) {
            return null;
        }

        return WaitlistEntry::findValidByToken($token);
    }
}
