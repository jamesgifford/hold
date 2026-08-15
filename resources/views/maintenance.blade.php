@php
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

    /*
     * ── Palette ─────────────────────────────────────────────────────────────
     * Set $bg and $accent to reskin — $text, $cardBg, and $inputBg derive
     * automatically from $bg (including switching to light text on a dark
     * background), so a basic reskin is a two-line change. Set any of the
     * three directly below (null means auto-derive, a real value wins
     * outright) to take manual control of just that one value instead.
     *
     * Plain PHP variables here, not literals inside the <style> block below,
     * like the mail templates' palette: picking a readable text color for an
     * arbitrary $bg needs real branching logic (compare WCAG contrast
     * against a light and a dark candidate, keep the higher), and there's no
     * broadly-supported CSS function that can do that yet — see
     * JamesGifford\Hold\Support\ColorTheme for the actual math.
     *
     * $accent is NOT derived from $bg — it also colors the submit button,
     * whose label is fixed white text, so pick one that reads well as a
     * background on its own.
     */
    $bg = '#f5f6f8';
    $accent = '#2563eb';

    $text = null;     // set to override the automatic light/dark derivation
    $cardBg = null;   // set to override the automatic card-background blend
    $inputBg = null;  // set to override; defaults to $bg (the "cutout" look)

    // How strongly $cardBg blends toward $text when auto-deriving (0-1;
    // higher = the card visibly departs further from $bg). Only applies
    // while $cardBg above is left null — has no effect once you set it.
    $cardBlendWeight = \JamesGifford\Hold\Support\ColorTheme::CARD_BLEND_WEIGHT;

    $text ??= \JamesGifford\Hold\Support\ColorTheme::textFor($bg);
    $cardBg ??= \JamesGifford\Hold\Support\ColorTheme::cardBackground($bg, $text, $cardBlendWeight);
    $inputBg ??= $bg;

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
        'note'           => 'We\'ll email you once when we\'re back. Unsubscribe anytime.',
        'honeypot_label' => 'Leave this field empty',  // off-screen; only bots see it
    ];
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
         * Edit these four values to match your brand. Everything below derives
         * from them, so a basic reskin is a three-line change.
         */
        :root {
            /*
             * These five are set from the PHP variables in the Palette
             * section at the top of this file, not hand-edited here —
             * editing a value on this line directly has no effect, since
             * Blade re-interpolates it from PHP on every render. Edit
             * $bg / $accent above; --hold-text / --hold-card-bg /
             * --hold-input-bg recompute from $bg automatically unless you
             * set $text / $cardBg / $inputBg above.
             */
            --hold-bg: {{ $bg }};
            --hold-card-bg: {{ $cardBg }};
            --hold-text: {{ $text }};
            --hold-accent: {{ $accent }};
            --hold-input-bg: {{ $inputBg }};

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
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
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
            border: 1px solid rgba(0, 0, 0, 0.15);
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
            background: rgba(22, 163, 74, 0.12);
            color: #15803d;
        }

        .hold-alert--error {
            background: rgba(185, 28, 28, 0.1);
            color: #b91c1c;
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
