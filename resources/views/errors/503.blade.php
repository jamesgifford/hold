@php
    /*
     * Published to the host app at resources/views/errors/503.blade.php. Laravel
     * renders this whenever the app is down (`php artisan down`) and a request
     * hits a non-excepted route.
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
    <title>Temporarily down for maintenance</title>
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
        <p class="hold-eyebrow">Maintenance</p>
        <h1>We'll be right back</h1>
        <p class="hold-lede">We're making some improvements and will be back online shortly. Leave your email and we'll let you know the moment we're back.</p>

        @if ($status === 'subscribed')
            <div class="hold-alert hold-alert--success" role="status">
                Thanks — we'll email you the moment we're back online.
            </div>
        @elseif ($status === 'invalid')
            <div class="hold-alert hold-alert--error" role="alert">
                Please enter a valid email address.
            </div>
        @endif

        <form class="hold-form" method="POST" action="{{ $action }}">
            <input type="hidden" name="context" value="maintenance">

            {{-- Honeypot: real people never see or fill this. --}}
            <div class="hold-hp" aria-hidden="true">
                <label for="{{ $honeypot }}">Leave this field empty</label>
                <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
            </div>

            <div class="hold-field">
                <label for="hold-email">Email address</label>
                <input type="email" id="hold-email" name="email"
                       placeholder="you@example.com" required autofocus>
            </div>

            <button type="submit" class="hold-button">Notify me</button>
        </form>

        <p class="hold-note">We'll email you once when we're back. Unsubscribe anytime.</p>
    </main>
</body>
</html>
