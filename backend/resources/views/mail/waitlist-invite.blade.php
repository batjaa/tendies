<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your invite is ready</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f5f2;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f7f5f2;padding:40px 20px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background-color:#ffffff;border-radius:2px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

{{-- Header --}}
<tr><td style="padding:28px 36px 24px;border-bottom:1px solid #e8e5e0;">
    <span style="font-weight:700;font-size:16px;color:#1a1917;">🍗 tendies</span>
</td></tr>

{{-- Content --}}
<tr><td style="padding:32px 36px;color:#1a1917;">

    <h1 style="font-size:22px;font-weight:800;letter-spacing:-0.01em;line-height:1.3;margin:0 0 16px;color:#1a1917;">Your spot is ready.</h1>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:0 0 16px;">You're in. Click below to connect your Schwab account and start seeing your realized P&L in the menu bar.</p>

    {{-- CTA --}}
    <a href="{{ url('/auth/waitlist/verify?token=' . $entry->invite_token) }}" style="display:block;width:100%;text-align:center;padding:14px 24px;background-color:#1a8a42;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:8px;margin:24px 0;box-sizing:border-box;">Accept Invite &amp; Connect Schwab</a>

    {{-- Expiry badge --}}
    <div style="display:inline-flex;align-items:center;gap:6px;font-family:'Courier New',monospace;font-size:12px;font-weight:500;color:#6b6860;background-color:#f7f5f2;padding:6px 12px;border-radius:6px;border:1px solid #e8e5e0;margin:8px 0 16px;">
        ⏱ Expires {{ $entry->invite_expires_at->format('F j, Y') }}
    </div>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:0 0 16px;">Clicking the button will open Schwab's login page. After you authorize, your account is linked and you're ready to go.</p>

    {{-- Divider --}}
    <div style="height:1px;background-color:#e8e5e0;margin:24px 0;"></div>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:0 0 16px;"><strong style="font-weight:600;color:#1a1917;">What you're getting:</strong></p>

    {{-- Feature list --}}
    <table cellpadding="0" cellspacing="0" style="width:100%;margin-bottom:16px;">
        <tr><td style="padding:4px 0;font-size:14px;color:#6b6860;font-weight:300;line-height:1.6;"><span style="color:#1a8a42;margin-right:8px;">✓</span> macOS menu bar app — your P&L next to the clock</td></tr>
        <tr><td style="padding:4px 0;font-size:14px;color:#6b6860;font-weight:300;line-height:1.6;"><span style="color:#1a8a42;margin-right:8px;">✓</span> Auto-refresh on your schedule (1–30 min)</td></tr>
        <tr><td style="padding:4px 0;font-size:14px;color:#6b6860;font-weight:300;line-height:1.6;"><span style="color:#1a8a42;margin-right:8px;">✓</span> Drill into tickers and individual executions</td></tr>
        <tr><td style="padding:4px 0;font-size:14px;color:#6b6860;font-weight:300;line-height:1.6;"><span style="color:#1a8a42;margin-right:8px;">✓</span> No Schwab developer credentials needed</td></tr>
        <tr><td style="padding:4px 0;font-size:14px;color:#6b6860;font-weight:300;line-height:1.6;"><span style="color:#1a8a42;margin-right:8px;">✓</span> 7-day free trial, then $5/mo or $40/yr</td></tr>
    </table>

    {{-- Signoff --}}
    <p style="font-size:14px;color:#6b6860;font-weight:300;margin:24px 0 0;">
        LFG,<br>
        <strong style="font-weight:500;color:#1a1917;">🍗 Tendies HQ</strong>
    </p>

</td></tr>

{{-- Footer --}}
<tr><td style="padding:20px 36px 24px;border-top:1px solid #e8e5e0;">
    <p style="font-size:12px;color:#9c978e;font-weight:300;line-height:1.6;margin:0;">This invite link is single-use and expires on {{ $entry->invite_expires_at->format('F j, Y') }}. If you didn't request this, you can safely ignore it — no action is needed.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
