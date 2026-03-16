<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
 * State machine:
 *
 * pending ──[Nova: Send Invite]──▶ invited ──[POST /auth/waitlist/register]──▶ accepted
 *   ▲ confirmation email              │ invite email sent                        │
 *   │ queued on signup                │                                          │
 *                                     ▼
 *                                (expired if
 *                                 invite_expires_at
 *                                 is past)
 */
class WaitlistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'status',
        'invite_token',
        'invited_at',
        'invite_expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function effectiveStatus(): string
    {
        if ($this->status === 'invited' && $this->isExpired()) {
            return 'expired';
        }

        return $this->status;
    }

    /**
     * Find an invited entry by token that hasn't expired or been accepted.
     */
    public static function findValidByToken(string $token): ?self
    {
        $entry = static::where('invite_token', $token)
            ->where('status', 'invited')
            ->first();

        if (! $entry || $entry->isExpired()) {
            return null;
        }

        return $entry;
    }

    public function isExpired(): bool
    {
        return $this->invite_expires_at && $this->invite_expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
