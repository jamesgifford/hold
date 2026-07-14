@php
    /*
     * Hold's self-contained team-notice email — the internal "hold enabled"
     * heads-up sent to your configured team addresses.
     *
     * No Laravel mail markdown, theme, or build step — everything is in this one
     * file. The team notification renders it via ->view(), passing only
     * $context; every user-visible string lives in the blocks below.
     *
     * ── Palette ────────────────────────────────────────────────────────────
     * Edit these five values to match your brand. They are plain PHP variables
     * (NOT CSS custom properties — email clients support those poorly) and are
     * interpolated into the inline styles below.
     */
    $bg     = '#f5f6f8';  // page background
    $card   = '#ffffff';  // card background
    $text   = '#1a1d24';  // body text
    $muted  = '#6b7280';  // secondary / footnote text
    $accent = '#2563eb';  // heading, links

    /*
     * ── Header (optional) ────────────────────────────────────────────────────
     * A logo image OR a text wordmark above the heading. Set at most one. Leave
     * BOTH null (the default) and no header — and no header spacing — renders at
     * all: the heading is the first thing in the card.
     */
    $logoUrl   = null;   // absolute, publicly hosted image URL (email clients
                         // cannot load local files); e.g. asset('images/logo.png').
                         // Any logo size works — it renders at $logoWidth with
                         // aspect ratio preserved. Renders best with a wide /
                         // landscape logo (roughly 3:1); for sharp high-DPI
                         // rendering use a source ~2-3x the rendered width
                         // (~300-450px wide for the default).
    $logoName  = null;   // text wordmark shown when no image is used,
                         // e.g. config('app.name')
    $logoAlt   = $logoName ?? config('app.name');  // img alt text (also used
                         // when a client blocks the remote image)
    $logoWidth = 150;    // normalized rendered width (px); sensible default,
                         // rarely needs changing

    /*
     * ── Copy ─────────────────────────────────────────────────────────────────
     * Edit the email's text here — each hold mode has its own set.
     */
    $copy = [
        'prelaunch' => [
            'heading' => 'Prelaunch ("coming soon") hold enabled',
            'body'    => [
                'Prelaunch ("coming soon") mode is now active for your application.',
                'Visitors are seeing the holding page and can leave their email to be notified.',
                'Run `php artisan jamesgifford:hold:announce` (or disable the hold with auto-announce enabled) when you are ready to email them.',
            ],
        ],
        'maintenance' => [
            'heading' => 'Maintenance hold enabled',
            'body'    => [
                'Maintenance mode is now active for your application.',
                'Visitors are seeing the holding page and can leave their email to be notified.',
                'Run `php artisan jamesgifford:hold:announce` (or disable the hold with auto-announce enabled) when you are ready to email them.',
            ],
        ],
    ];
    // Small line beneath the card.
    $footnote = 'Internal notification — sent to your configured team addresses.';

    /* Resolve this send's set from $context (the notification passes the enum). */
    $mode       = $context instanceof \JamesGifford\Hold\HoldSignupContext ? $context->value : ($context ?? 'prelaunch');
    $set        = $copy[$mode] ?? $copy['prelaunch'];
    $heading    = $set['heading'];
    $lines      = (array) $set['body'];
    $actionUrl  = null;   // the team notice carries no call-to-action button
    $actionText = null;
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; width:100%; background:{{ $bg }}; color:{{ $text }}; -webkit-text-size-adjust:100%; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; line-height:1.6;">
    <!-- hold:team -->

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:520px;">
                    <tr>
                        <td style="background:{{ $card }}; border-radius:12px; padding:40px 40px 32px;">
                            @if ($logoUrl)
                                <div style="margin:0 0 24px; text-align:center;">
                                    <img src="{{ $logoUrl }}" alt="{{ $logoAlt }}" width="{{ $logoWidth }}" style="display:inline-block; width:{{ $logoWidth }}px; max-width:100%; height:auto; border:0; outline:none; -ms-interpolation-mode:bicubic;">
                                </div>
                            @elseif ($logoName)
                                {{-- Edit the wordmark's look here --}}
                                <div style="margin:0 0 24px; text-align:center; font-size:22px; font-weight:800; letter-spacing:0.5px; color:{{ $accent }};">{{ $logoName }}</div>
                            @endif
                            <h1 style="margin:0 0 20px; font-size:24px; line-height:1.3; font-weight:700; color:{{ $accent }};">{{ $heading }}</h1>
                            @foreach ($lines as $line)
                                <p style="margin:0 0 16px; font-size:16px; color:{{ $text }};">{{ $line }}</p>
                            @endforeach
                            @if ($actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 4px;">
                                    <tr>
                                        <td align="center" style="border-radius:8px; background:{{ $accent }};">
                                            <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display:inline-block; padding:12px 28px; font-size:16px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $actionText }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    @if ($footnote)
                        <tr>
                            <td style="padding:20px 40px 0; font-size:13px; line-height:1.5; color:{{ $muted }};">
                                {{ $footnote }}
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
