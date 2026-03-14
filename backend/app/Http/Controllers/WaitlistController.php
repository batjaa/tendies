<?php

namespace App\Http\Controllers;

use App\Models\WaitlistEntry;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    public function signup(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:waitlist_entries,email',
        ]);

        $entry = WaitlistEntry::create($validated);

        $position = WaitlistEntry::where('status', 'pending')
            ->where('id', '<=', $entry->id)
            ->count();

        return response()->json([
            'message' => "You're on the list!",
            'position' => $position,
        ], 201);
    }
}
