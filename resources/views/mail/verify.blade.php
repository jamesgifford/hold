@php
    /*
     * Hold's self-contained verification email — "confirm your email
     * address," sent when config verification.required is true (the
     * default) so an address must be confirmed by its own owner before the
     * announcer will ever email it.
     *
     * No Laravel mail markdown, theme, or build step — everything is in this
     * one file. The notification renders it via ->view(), passing $signup
     * and $verifyUrl (a signed, expiring link it mints); every user-visible
     * string lives in the blocks below.
     *
     * ── Palette ────────────────────────────────────────────────────────────
     * Set $bg to reskin — everything below derives from it automatically.
     * Leave a variable null to auto-derive it, or set it directly to
     * override just that one value. See ColorTheme for the derivation math.
     * Plain PHP variables (NOT CSS custom properties — email clients support
     * those poorly), interpolated straight into the inline styles below.
     * Auto-derivation itself checks the appearance config section first
     * (see Hold::appearance()) — set a value once in config to apply
     * it everywhere, or scope it to just the mail templates or just the
     * holding pages, without touching this file at all.
     */
    $colorTheme = \JamesGifford\Hold\Support\ColorTheme::class;
    $hold = \JamesGifford\Hold\Hold::class;

    $bg = null;
    $accent = null;    // set to override the automatic hue-matched derivation
    $text = null;      // set to override the automatic light/dark derivation
    $card = null;      // set to override the automatic card-background blend
    $muted = null;     // set to override the automatic secondary/footnote-text blend
    $cardBlendWeight = $hold::appearance('card_blend_weight', 'mail') ?? $colorTheme::CARD_BLEND_WEIGHT;    // 0-1; how strongly $card blends toward $text
    $mutedBlendWeight = $hold::appearance('muted_blend_weight', 'mail') ?? $colorTheme::MUTED_BLEND_WEIGHT;  // 0-1; how strongly $muted blends from $bg toward $text

    // Derive anything left null above from $bg:
    $bg ??= $hold::appearance('bg', 'mail');
    $accent ??= $hold::appearance('accent', 'mail') ?? $colorTheme::accentFor($bg);
    $text ??= $hold::appearance('text', 'mail') ?? $colorTheme::textFor($bg);
    $card ??= $hold::appearance('card', 'mail') ?? $colorTheme::cardBackground($bg, $text, $cardBlendWeight);

    // The footnote's real backdrop is $bg, not $card — it sits outside the card.
    $muted ??= $hold::appearance('muted', 'mail') ?? $colorTheme::blend($bg, $text, $mutedBlendWeight);

    /*
     * ── Spacing ──────────────────────────────────────────────────────────────
     * Base spacing unit driving the vertical rhythm between the card's stacked
     * elements below (header, heading, body paragraphs, button) — plain PHP,
     * not a CSS custom property, for the same reason as the palette above
     * (email clients support those poorly). Every margin below is a whole
     * multiple of this one value.
     */
    $space = 4;  // px

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
     * Edit the email's text here. $verifyUrl (the signed, expiring link) is
     * passed in by the notification — it can't live in this block as a
     * static string.
     */
    $copy = [
        'heading' => 'Confirm your email address',
        'body'    => 'One more step — click below to confirm this address and finish signing up.',
        'button'  => 'Confirm email',
    ];
    // Small line beneath the card.
    $footnote = 'If you didn\'t request this, you can safely ignore it.';

    $heading    = $copy['heading'];
    $lines      = (array) $copy['body'];
    $actionUrl  = $verifyUrl;
    $actionText = $copy['button'];
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
    <!-- hold:verify -->

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:520px;">
                    <tr>
                        <td style="background:{{ $card }}; border-radius:12px; padding:40px 40px 32px;">
                            @if ($logoUrl)
                                <div style="margin:0 0 {{ $space * 6 }}px; text-align:center;">
                                    <img src="{{ $logoUrl }}" alt="{{ $logoAlt }}" width="{{ $logoWidth }}" style="display:inline-block; width:{{ $logoWidth }}px; max-width:100%; height:auto; border:0; outline:none; -ms-interpolation-mode:bicubic;">
                                </div>
                            @elseif ($logoName)
                                {{-- Edit the wordmark's look here --}}
                                <div style="margin:0 0 {{ $space * 6 }}px; text-align:center; font-size:22px; font-weight:800; letter-spacing:0.5px; color:{{ $accent }};">{{ $logoName }}</div>
                            @endif
                            <h1 style="margin:0 0 {{ $space * 5 }}px; font-size:24px; line-height:1.3; font-weight:700; color:{{ $accent }};">{{ $heading }}</h1>
                            @foreach ($lines as $line)
                                <p style="margin:0 0 {{ $space * 4 }}px; font-size:16px; color:{{ $text }};">{{ $line }}</p>
                            @endforeach
                            @if ($actionUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:{{ $space * 2 }}px 0 {{ $space }}px;">
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
