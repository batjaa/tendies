<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>You're #{{ $position }} on the waitlist</title>
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

    <h1 style="font-size:22px;font-weight:800;letter-spacing:-0.01em;line-height:1.3;margin:0 0 16px;color:#1a1917;">You're on the list.</h1>

    {{-- Position block --}}
    <table cellpadding="0" cellspacing="0" style="width:100%;margin:24px 0;">
    <tr><td style="padding:20px 24px;background-color:#f7f5f2;border-left:3px solid #1a8a42;border-radius:0 4px 4px 0;">
        <span style="font-family:'Courier New',monospace;font-size:32px;font-weight:700;color:#1a8a42;line-height:1;">#{{ $position }}</span>
        <span style="font-size:14px;color:#6b6860;font-weight:300;padding-left:10px;">in line for early access</span>
    </td></tr>
    </table>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:0 0 16px;">We're onboarding people gradually as we scale up. When your spot opens, you'll get an invite email with a direct link to connect your Schwab account.</p>

    {{-- Divider --}}
    <div style="height:1px;background-color:#e8e5e0;margin:24px 0;"></div>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:0 0 16px;"><strong style="font-weight:600;color:#1a1917;">While you wait</strong> — the CLI is free and open source. Install it now to start tracking your realized P&L from the terminal:</p>

    {{-- Code block --}}
    <div style="font-family:'Courier New',monospace;font-size:13px;background-color:#f7f5f2;padding:14px 18px;border-radius:6px;border:1px solid #e8e5e0;color:#1a1917;line-height:1.7;margin:12px 0;">
        <span style="color:#1a8a42;">$</span> brew install batjaa/tap/tendies
    </div>

    <p style="font-size:15px;color:#6b6860;font-weight:300;line-height:1.7;margin:16px 0 0;">One command gives you day, week, month, and year-to-date P&L with FIFO lot matching.</p>

    {{-- Signoff --}}
    <p style="font-size:14px;color:#6b6860;font-weight:300;margin:24px 0 0;">
        HODL tight and go make some tendies,<br>
        <strong style="font-weight:500;color:#1a1917;">🍗 Tendies HQ</strong>
    </p>

</td></tr>

{{-- Footer --}}
<tr><td style="padding:20px 36px 24px;border-top:1px solid #e8e5e0;">
    <p style="font-size:12px;color:#9c978e;font-weight:300;line-height:1.6;margin:0;">You're receiving this because you signed up at <a href="{{ config('app.url') }}" style="color:#6b6860;text-decoration:underline;text-underline-offset:2px;">mytendies.app</a>. If this wasn't you, ignore this email.</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
