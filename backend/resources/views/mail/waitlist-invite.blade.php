<x-mail::message>
# You're invited to Tendies!

Great news — your spot is ready. Click the button below to connect your Schwab account and start tracking your P&L.

<x-mail::button :url="url('/auth/waitlist/verify?token=' . $entry->invite_token)">
Accept Your Invite
</x-mail::button>

This invite expires on **{{ $entry->invite_expires_at->format('F j, Y') }}**, so don't wait too long.

If you didn't sign up for Tendies, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
