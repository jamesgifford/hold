@php
    $colorTheme = \JamesGifford\Hold\Support\ColorTheme::class;
    $hold = \JamesGifford\Hold\Hold::class;

    /*
     * ── Palette ─────────────────────────────────────────────────────────────
     * Set $bg to reskin — everything below derives from it automatically.
     * Leave a variable null to auto-derive it, or set it directly to
     * override just that one value. See ColorTheme for the derivation math.
     * Auto-derivation itself checks the appearance config section first
     * (see Hold::appearance()) — set a value once in config to apply
     * it everywhere, or scope it to just the holding pages or just the mail
     * templates, without touching this file at all.
     */
    $bg = null;
    $accent = null;            // set to override the automatic hue-matched derivation
    $text = null;              // set to override the automatic light/dark derivation
    $cardBg = null;            // set to override the automatic card-background blend
    $inputBg = null;           // set to override; defaults to $bg (the "cutout" look)
    $inputBorder = null;       // set to override the automatic border-color blend
    $cardShadowColor = null;   // set to override the automatic light-shadow/dark-glow choice
    $alertSuccessBg = null;    // set to override the automatic success-alert background blend
    $alertSuccessText = null;  // set to override the automatic success-alert text derivation
    $alertErrorBg = null;      // set to override the automatic error-alert background blend
    $alertErrorText = null;    // set to override the automatic error-alert text derivation
    $cardBlendWeight = $hold::appearance('card_blend_weight', 'pages') ?? $colorTheme::CARD_BLEND_WEIGHT; // 0-1; how strongly $cardBg blends toward $text

    // Derive anything left null above from $bg:
    $bg ??= $hold::appearance('bg', 'pages');
    $accent ??= $hold::appearance('accent', 'pages') ?? $colorTheme::accentFor($bg);
    $text ??= $hold::appearance('text', 'pages') ?? $colorTheme::textFor($bg);
    $cardBg ??= $hold::appearance('card', 'pages') ?? $colorTheme::cardBackground($bg, $text, $cardBlendWeight);
    $inputBg ??= $hold::appearance('input_bg', 'pages') ?? $bg;
    $inputBorder ??= $hold::appearance('input_border', 'pages') ?? $colorTheme::blend($inputBg, $text, $colorTheme::INPUT_BORDER_BLEND_WEIGHT);
    $cardShadowColor ??= $hold::appearance('card_shadow_color', 'pages') ?? $colorTheme::betterContrast($bg, '#000000', '#ffffff');
    $cardShadowRgb = implode(', ', $colorTheme::toRgb($cardShadowColor));

    // Alerts tint $cardBg, not $bg — the alert renders inside the card, so
    // $cardBg is its real backdrop.
    $alertSuccessBg ??= $hold::appearance('alert_success_bg', 'pages') ?? $colorTheme::blend($cardBg, $colorTheme::ALERT_SUCCESS_TINT, $colorTheme::ALERT_SUCCESS_BLEND_WEIGHT);
    $alertSuccessText ??= $hold::appearance('alert_success_text', 'pages') ?? $colorTheme::betterContrast($alertSuccessBg, $colorTheme::ALERT_SUCCESS_TEXT_LIGHT, $colorTheme::ALERT_SUCCESS_TEXT_DARK);
    $alertErrorBg ??= $hold::appearance('alert_error_bg', 'pages') ?? $colorTheme::blend($cardBg, $colorTheme::ALERT_ERROR_TINT, $colorTheme::ALERT_ERROR_BLEND_WEIGHT);
    $alertErrorText ??= $hold::appearance('alert_error_text', 'pages') ?? $colorTheme::betterContrast($alertErrorBg, $colorTheme::ALERT_ERROR_TEXT_LIGHT, $colorTheme::ALERT_ERROR_TEXT_DARK);

    /*
     * ── Copy ─────────────────────────────────────────────────────────────────
     * Edit this page's text here. Every user-visible string lives in this block,
     * including both form-state messages ('success' after a signup, 'invalid'
     * for a bad email) — so you can review and reword every state in one place
     * without having to trigger it.
     */
    $copy = [
        'title'          => 'Temporarily down for maintenance',  // browser tab / <title>
        'eyebrow'        => 'Maintenance',                        // small label above the heading
        'heading'        => 'We\'ll be right back',
        'lede'           => 'We\'re making some improvements and will be back online shortly. Leave your email and we\'ll let you know the moment we\'re back.',

        // Maintenance-only extras below — each renders ONLY when non-empty
        // (and, like the mail template's header, adds no spacing when it
        // doesn't). 'apology' ships with default wording since it's almost
        // always wanted; 'eta' and 'contact' default to empty and are
        // entirely per-app. Keep 'eta' concrete — a real time/date beats a
        // vague "soon" — and 'contact' a channel that's actually staffed
        // right now, not a generic support address nobody's watching.
        'eta'            => '',  // plain-language return estimate, e.g. 'We expect to be back by 3pm PT'
        'apology'        => 'We know this is inconvenient — thank you for your patience.',
        'contact'        => '',  // optional; plain text or an <a href="mailto:...">link</a> — rendered UNESCAPED, see markup below

        'success'        => 'Thanks — we\'ll email you the moment we\'re back online.',
        'invalid'        => 'Please enter a valid email address.',
        'email_label'    => 'Email address',
        'email_placeholder' => 'you@example.com',
        'button'         => 'Notify me',
        'note'           => 'We\'ll email you once when we\'re back.',
        'honeypot_label' => 'Leave this field empty',  // off-screen; only bots see it
    ];

    /*
     * The maintenance-mode capture page. Published to the host app at
     * resources/views/vendor/hold/maintenance.blade.php (edit it there); the
     * published resources/views/errors/503.blade.php is a thin shim that renders
     * this view when Laravel is down (`php artisan down`).
     *
     * IMPORTANT: this page renders during an aborted maintenance request, before
     * the session middleware runs — so it must not depend on session, @csrf, or
     * old(). The signup route is CSRF-exempt and reachable during maintenance
     * (the package merges it into the maintenance `except` list); feedback comes
     * back as a `?hold=` query param. Do NOT switch the app to `down --render`:
     * that bypasses the HTTP kernel and the signup POST would stop working.
     */
    $prefix = trim((string) config('jamesgifford.hold.routes.prefix', 'hold'), '/');
    $action = url($prefix.'/signup');
    $honeypot = config('jamesgifford.hold.spam.honeypot_field', 'website');
    $status = request('hold');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['title'] }}</title>
    {{-- Secondary protection behind the 503 status: keeps this page itself
         out of search results even if a crawler somehow indexes it. --}}
    <meta name="robots" content="noindex, nofollow">
    <style>
        /*
         * ── Palette ────────────────────────────────────────────────────────────
         * Edit $bg above to match your brand — everything below derives from
         * it automatically, so a basic reskin is a one-line change.
         */
        :root {
            /*
             * These are set from the PHP variables in the Palette section
             * at the top of this file, not hand-edited here — editing a
             * value on this line directly has no effect, since Blade
             * re-interpolates it from PHP on every render. Edit $bg above;
             * everything else recomputes from it automatically unless you
             * set its own variable above.
             */
            --hold-bg: {{ $bg }};
            --hold-card-bg: {{ $cardBg }};
            --hold-text: {{ $text }};
            --hold-accent: {{ $accent }};
            --hold-input-bg: {{ $inputBg }};
            --hold-input-border: {{ $inputBorder }};
            --hold-card-shadow-color: {{ $cardShadowRgb }};
            --hold-alert-success-bg: {{ $alertSuccessBg }};
            --hold-alert-success-text: {{ $alertSuccessText }};
            --hold-alert-error-bg: {{ $alertErrorBg }};
            --hold-alert-error-text: {{ $alertErrorText }};

            /* Reading width: ~60-70 characters per line at the base font
               size, not an arbitrary pixel value — keeps prose comfortable
               regardless of copy length. */
            --hold-content-width: 65ch;

            /* Single spacing value driving the vertical rhythm between the
               card's stacked elements below, instead of ad-hoc per-element
               margins — this is what keeps the layout sane as optional
               elements (eta/apology/contact/alert) come and go. */
            --hold-space: 1.25rem;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--hold-bg);
            color: var(--hold-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }

        .hold-card {
            width: 100%;
            max-width: var(--hold-content-width);
            background: var(--hold-card-bg);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            box-shadow: 0 10px 40px rgba(var(--hold-card-shadow-color), 0.08);
            text-align: center;
        }

        /* Vertical rhythm: every direct child of the card gets no margin of
           its own, and a single top margin from --hold-space once it has a
           preceding sibling — so spacing stays consistent no matter which
           optional elements (alert/eta/apology/contact) are present. */
        .hold-card > * {
            margin: 0;
        }

        .hold-card > * + * {
            margin-top: var(--hold-space);
        }

        .hold-eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--hold-accent);
        }

        .hold-card h1 {
            font-size: 1.75rem;
            line-height: 1.2;
        }

        .hold-lede {
            opacity: 0.75;
        }

        .hold-apology {
            opacity: 0.75;
        }

        .hold-eta {
            font-weight: 600;
        }

        .hold-contact {
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .hold-form {
            display: flex;
            flex-direction: column;
            gap: calc(var(--hold-space) * 0.6);
            text-align: left;
        }

        .hold-field label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .hold-field input[type="email"] {
            width: 100%;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            border: 1px solid var(--hold-input-border);
            border-radius: 10px;
            background: var(--hold-input-bg);
            color: var(--hold-text);
        }

        .hold-field input[type="email"]:focus {
            outline: 2px solid var(--hold-accent);
            outline-offset: 1px;
        }

        /* Honeypot: kept in the DOM for bots, removed from view and a11y tree. */
        .hold-hp {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        .hold-button {
            padding: 0.85rem 1rem;
            font-size: 1rem;
            font-weight: 600;
            color: #fff;
            background: var(--hold-accent);
            border: none;
            border-radius: 10px;
            cursor: pointer;
        }

        .hold-button:hover { filter: brightness(1.05); }

        .hold-note {
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .hold-alert {
            border-radius: 10px;
            padding: 0.85rem 1rem;
            font-size: 0.95rem;
            text-align: left;
        }

        .hold-alert--success {
            background: var(--hold-alert-success-bg);
            color: var(--hold-alert-success-text);
        }

        .hold-alert--error {
            background: var(--hold-alert-error-bg);
            color: var(--hold-alert-error-text);
        }
    </style>
</head>
<body>
    <main class="hold-card">
        <p class="hold-eyebrow">{{ $copy['eyebrow'] }}</p>
        <h1>{{ $copy['heading'] }}</h1>
        <p class="hold-lede">{{ $copy['lede'] }}</p>

        @if ($copy['apology'] !== '')
            <p class="hold-apology">{{ $copy['apology'] }}</p>
        @endif

        @if ($copy['eta'] !== '')
            <p class="hold-eta">{{ $copy['eta'] }}</p>
        @endif

        @if ($status === 'subscribed')
            <div class="hold-alert hold-alert--success" role="status">
                {{ $copy['success'] }}
            </div>
        @elseif ($status === 'invalid')
            <div class="hold-alert hold-alert--error" role="alert">
                {{ $copy['invalid'] }}
            </div>
        @endif

        <form class="hold-form" method="POST" action="{{ $action }}">
            <input type="hidden" name="context" value="maintenance">

            {{-- Honeypot: real people never see or fill this. --}}
            <div class="hold-hp" aria-hidden="true">
                <label for="{{ $honeypot }}">{{ $copy['honeypot_label'] }}</label>
                <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
            </div>

            <div class="hold-field">
                <label for="hold-email">{{ $copy['email_label'] }}</label>
                <input type="email" id="hold-email" name="email"
                       placeholder="{{ $copy['email_placeholder'] }}" required autofocus>
            </div>

            <button type="submit" class="hold-button">{{ $copy['button'] }}</button>
        </form>

        @if ($copy['contact'] !== '')
            <p class="hold-contact">{!! $copy['contact'] !!}</p>
        @endif

        <p class="hold-note">{{ $copy['note'] }}</p>
    </main>
</body>
</html>
