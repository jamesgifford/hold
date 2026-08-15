@php
    /*
     * Resolved once so the markup stays declarative.
     *
     * The form posts to the package signup route, built from the configured
     * prefix so it works even when named routes aren't loaded. Feedback comes
     * back as a `?hold=` query param, NOT session flash: this page can render
     * from global middleware BEFORE Laravel starts the session (and the 503
     * page renders during an aborted maintenance request), so session/@csrf are
     * not dependable here. The signup route is CSRF-exempt for the same reason;
     * honeypot + rate limiting guard the endpoint.
     */
    $prefix = trim((string) config('jamesgifford.hold.routes.prefix', 'hold'), '/');
    $action = url($prefix.'/signup');
    $honeypot = config('jamesgifford.hold.spam.honeypot_field', 'website');
    $status = request('hold');

    /*
     * ── Copy ─────────────────────────────────────────────────────────────────
     * Edit this page's text here. Every user-visible string lives in this block,
     * including both form-state messages ('success' after a signup, 'invalid'
     * for a bad email) — so you can review and reword every state in one place
     * without having to trigger it.
     *
     * 'title' and 'lede' double as sharing metadata (see below): make 'title'
     * your actual app/product name, and 'lede' one specific promise about what
     * it does or will do, in plain language — not generic marketing copy.
     */
    $copy = [
        'title'          => 'Launching soon',       // browser tab / <title>
        'eyebrow'        => 'Coming soon',           // small label above the heading
        'heading'        => 'We\'re launching soon',
        'lede'           => 'Leave your email and we\'ll let you know the moment we\'re live.',
        'success'        => 'You\'re on the list. We\'ll be in touch when we launch.',
        'invalid'        => 'Please enter a valid email address.',
        'email_label'    => 'Email address',
        'email_placeholder' => 'you@example.com',
        'button'         => 'Notify me',
        'note'           => 'We\'ll email you once when we\'re live. Unsubscribe anytime.',
        'honeypot_label' => 'Leave this field empty',  // off-screen; only bots see it
    ];

    /*
     * ── Sharing metadata ─────────────────────────────────────────────────────
     * The prelaunch page is meant to be found and shared, so it gets proper
     * title/description/Open Graph/Twitter Card tags driven by $copy above.
     * The maintenance page deliberately does NOT get these (see its meta
     * robots noindex instead).
     */
    $metaTitle = $copy['title'] !== '' ? $copy['title'] : (string) config('app.name', 'Coming soon');
    $metaDescription = $copy['lede'] !== '' ? $copy['lede'] : $metaTitle;
    $currentUrl = url()->current();

    // Absolute, publicly reachable image URL for social share previews — same
    // requirement as the email logo (a local/relative path won't resolve for a
    // crawler or a chat app's link-preview fetcher). Conventional size ~1200x630.
    // Leave null to omit og:image (twitter:card then falls back to 'summary').
    $ogImage = null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">

    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $currentUrl }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <style>
        /*
         * ── Palette ────────────────────────────────────────────────────────────
         * Edit these four values to match your brand. Everything below derives
         * from them, so a basic reskin is a three-line change.
         */
        :root {
            --hold-bg: #f5f6f8;
            --hold-card-bg: #ffffff;
            --hold-text: #1a1d24;
            --hold-accent: #2563eb;

            /* The email field's fill. Defaults to the same color as --hold-bg
               (so the field reads as a cutout showing the page behind the
               card) but is its own variable — change --hold-bg for the page
               background alone without also recoloring the input. */
            --hold-input-bg: #f5f6f8;

            /* Reading width: ~60-70 characters per line at the base font
               size, not an arbitrary pixel value — keeps prose comfortable
               regardless of copy length. */
            --hold-content-width: 65ch;

            /* Single spacing value driving the vertical rhythm between the
               card's stacked elements below, instead of ad-hoc per-element
               margins — this is what keeps the layout sane as optional
               elements (alert, etc.) come and go. */
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
           optional elements (e.g. the alert) are present. */
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
            <input type="hidden" name="context" value="prelaunch">

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

        <p class="hold-note">{{ $copy['note'] }}</p>
    </main>
</body>
</html>
