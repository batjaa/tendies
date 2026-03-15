<?php

namespace App\Services;

use App\Mail\WaitlistInviteMail;
use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WaitlistService
{
    /**
     * Send invites to the given waitlist entries.
     * Skips entries that are not in 'pending' status (prevents double-send).
     *
     * @return array{sent: int, skipped: int}
     */
    public function sendInvites(Collection $entries): array
    {
        $sent = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if (! $entry->isPending()) {
                $skipped++;

                continue;
            }

            $entry->update([
                'status' => 'invited',
                'invite_token' => Str::random(64),
                'invited_at' => now(),
                'invite_expires_at' => now()->addDays(7),
            ]);

            try {
                Mail::to($entry->email)->send(new WaitlistInviteMail($entry));
            } catch (\Throwable $e) {
                $entry->update([
                    'status' => 'pending',
                    'invite_token' => null,
                    'invited_at' => null,
                    'invite_expires_at' => null,
                ]);
                $skipped++;

                continue;
            }

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
