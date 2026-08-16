@php
    $colorTheme = \JamesGifford\Hold\Support\ColorTheme::class;
    $hold = \JamesGifford\Hold\Hold::class;

    /*
     * ── Palette ─────────────────────────────────────────────────────────────
     * Same reskin story as prelaunch.blade.php / maintenance.blade.php: set
     * $bg and everything below derives automatically. This page has no form
     * or alert state, so it only carries the palette variables it actually
     * renders with.
     */
    $bg = null;
    $accent = null;          // set to override the automatic hue-matched derivation
    $text = null;            // set to override the automatic light/dark derivation
    $cardBg = null;          // set to override the automatic card-background blend
    $cardShadowColor = null; // set to override the automatic light-shadow/dark-glow choice
    $cardBlendWeight = $hold::appearance('card_blend_weight', 'pages') ?? $colorTheme::CARD_BLEND_WEIGHT;

    // Derive anything left null above from $bg:
    $bg ??= $hold::appearance('bg', 'pages');
    $accent ??= $hold::appearance('accent', 'pages') ?? $colorTheme::accentFor($bg);
    $text ??= $hold::appearance('text', 'pages') ?? $colorTheme::textFor($bg);
    $cardBg ??= $hold::appearance('card', 'pages') ?? $colorTheme::cardBackground($bg, $text, $cardBlendWeight);
    $cardShadowColor ??= $hold::appearance('card_shadow_color', 'pages') ?? $colorTheme::betterContrast($bg, '#000000', '#ffffff');
    $cardShadowRgb = implode(', ', $colorTheme::toRgb($cardShadowColor));

    /*
     * ── Copy ─────────────────────────────────────────────────────────────────
     * Edit this page's text here.
     */
    $copy = [
        'title'   => 'Unsubscribed',
        'heading' => 'You\'re unsubscribed',
        'body'    => 'You won\'t receive any more emails from us. If this was a mistake, just sign up again.',
        'link'    => 'Back to the site',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['title'] }}</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --hold-bg: {{ $bg }};
            --hold-card-bg: {{ $cardBg }};
            --hold-text: {{ $text }};
            --hold-accent: {{ $accent }};
            --hold-card-shadow-color: {{ $cardShadowRgb }};
            --hold-content-width: 65ch;
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

        .hold-card > * { margin: 0; }
        .hold-card > * + * { margin-top: var(--hold-space); }

        .hold-card h1 { font-size: 1.75rem; line-height: 1.2; }

        .hold-lede { opacity: 0.75; }

        .hold-link {
            display: inline-block;
            font-weight: 600;
            color: var(--hold-accent);
        }
    </style>
</head>
<body>
    <main class="hold-card">
        <h1>{{ $copy['heading'] }}</h1>
        <p class="hold-lede">{{ $copy['body'] }}</p>
        <a class="hold-link" href="{{ url('/') }}">{{ $copy['link'] }}</a>
    </main>
</body>
</html>
