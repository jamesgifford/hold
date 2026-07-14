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
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['title'] }}</title>
    <style>
        /*
         * Reskin knobs: edit these four values to match your brand. Everything
         * below derives from them, so a basic reskin is a three-line change.
         */
        :root {
            --hold-bg: #f5f6f8;
            --hold-card-bg: #ffffff;
            --hold-text: #1a1d24;
            --hold-accent: #2563eb;
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
            max-width: 30rem;
            background: var(--hold-card-bg);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .hold-eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--hold-accent);
            margin: 0 0 0.75rem;
        }

        .hold-card h1 {
            margin: 0 0 0.75rem;
            font-size: 1.75rem;
            line-height: 1.2;
        }

        .hold-lede {
            margin: 0 0 1.75rem;
            opacity: 0.75;
        }

        .hold-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
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
            background: var(--hold-bg);
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
            margin: 1rem 0 0;
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .hold-alert {
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.25rem;
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
