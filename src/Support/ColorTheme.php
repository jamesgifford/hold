<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

use InvalidArgumentException;

/**
 * Pure color-math behind the holding pages' "set $bg alone, everything else
 * adapts" reskin story.
 *
 * Pure CSS cannot branch between two fixed text colors based on an arbitrary
 * developer-chosen background — CSS relative color syntax can extract a
 * lightness channel, but there is no broadly-shipped CSS if()/comparison
 * function to branch on it yet — so this happens here, in PHP, at render
 * time. No framework dependency: plain math over hex strings, so it runs —
 * and is unit tested — without booting an app.
 */
final class ColorTheme
{
    /**
     * Candidate text colors compared against a chosen background; whichever
     * gives the higher WCAG contrast ratio wins. These are the holding
     * pages' own existing near-black/near-white defaults (not pure #000/
     * #fff) — on-brand with the rest of the palette, still high-contrast
     * against any reasonable background.
     */
    public const DARK_TEXT = '#1a1d24';

    public const LIGHT_TEXT = '#f5f6f8';

    /** Default weight for cardBackground()'s blend() call. */
    public const CARD_BLEND_WEIGHT = 0.12;

    /**
     * Fixed hue-preserving accent saturation/lightness (0-1) — deliberately
     * vibrant, not $bg's own (often much softer, sometimes near-zero)
     * saturation/lightness. Validated across the full hue wheel: every hue
     * either already clears ACCENT_MIN_CONTRAST at this L, or is darkened
     * to it by accentAtHue() below.
     */
    public const ACCENT_SATURATION = 0.65;

    public const ACCENT_LIGHTNESS = 0.47;

    /**
     * WCAG AA minimum contrast ratio for normal text (4.5:1) — the bar
     * accentFor() enforces against white, since the accent is also the
     * submit button's background behind its fixed white label.
     */
    public const ACCENT_MIN_CONTRAST = 4.5;

    /**
     * Below this chroma (0-1; max(r,g,b) - min(r,g,b) as a fraction of 1 —
     * how colored $bg actually is, independent of how light or dark it is),
     * $bg's hue is numerically unreliable: a single sRGB rounding unit can
     * swing a near-gray color's computed hue by tens of degrees (the
     * package's own default $bg, #f5f6f8, is one such case). Below this,
     * accentFor() falls back to ACCENT_FALLBACK_HUE instead of trusting the
     * noise. Deliberately NOT gated on HSL saturation directly — raw S is
     * itself misleading near white/black (it can read >90% for an
     * obviously near-gray color, because its denominator shrinks toward
     * the lightness extremes).
     */
    public const ACCENT_MIN_CHROMA = 0.10;

    /**
     * Fallback hue (degrees) used when $bg's own hue is unreliable (see
     * ACCENT_MIN_CHROMA) — the current shipped accent's (#2563eb) own hue,
     * so a near-neutral $bg keeps the same familiar blue family rather than
     * an arbitrary one.
     */
    public const ACCENT_FALLBACK_HUE = 221.21;

    /**
     * Bisection iteration count for accentAtHue()'s lightness search. 20
     * halvings of an at-most-1.0-wide interval converge to well under
     * 1e-6 — far finer than 8-bit hex rounding can even represent — so a
     * fixed count is both plenty and trivially, unconditionally
     * terminating (no convergence-tolerance loop to get wrong).
     */
    private const ACCENT_LIGHTNESS_SEARCH_ITERATIONS = 20;

    /**
     * The better-contrast of DARK_TEXT/LIGHT_TEXT against a given
     * background. Ties favor DARK_TEXT.
     */
    public static function textFor(string $bg): string
    {
        $darkContrast = self::contrastRatio($bg, self::DARK_TEXT);
        $lightContrast = self::contrastRatio($bg, self::LIGHT_TEXT);

        return $lightContrast > $darkContrast ? self::LIGHT_TEXT : self::DARK_TEXT;
    }

    /**
     * A card background derived from the page background, nudged toward the
     * chosen text color by $weight (0-1).
     *
     * Blends toward $text — not toward a fixed white/black — because $text is
     * shared by the body copy (against $bg) and everything inside the card
     * (inherited against $cardBg: the heading, the field label, ...). A light
     * $bg still needs a light-ish card and a dark $bg still needs a dark-ish
     * card for that one $text value to stay legible in both places; blending
     * toward a fixed white would break that for a dark $bg (near-white text
     * on a forced-white card).
     */
    public static function cardBackground(string $bg, string $text, float $weight = self::CARD_BLEND_WEIGHT): string
    {
        return self::blend($bg, $text, $weight);
    }

    /**
     * An accent that shares $bg's hue — rather than a fixed, independent
     * color — so it can't clash with $bg the way an unrelated hue can (a
     * saturated color that individually looks fine, like blue on teal,
     * still reads as a mistake next to an unrelated background). The hue
     * is reconstructed at a fixed, deliberately vibrant saturation/
     * lightness (ACCENT_SATURATION/ACCENT_LIGHTNESS), not $bg's own
     * saturation/lightness — $bg is often soft, pastel, or near-neutral,
     * unsuited to an accent's actual job (button fill, focus ring, label
     * color) on its own.
     *
     * $bg's hue is only trustworthy when $bg is meaningfully colored — see
     * ACCENT_MIN_CHROMA. Below that, this uses ACCENT_FALLBACK_HUE instead.
     *
     * Note: hue-matching the accent to $bg necessarily makes it a *worse*
     * match against $bg for contrast purposes (a same-hue color is harder
     * to contrast against its source than an arbitrary one) — this
     * deliberately optimizes for the button's white-text legibility (see
     * accentAtHue()) and for not clashing, not for maximum eyebrow-label
     * contrast against $bg, which stays a secondary, unenforced concern.
     */
    public static function accentFor(string $bg): string
    {
        [$hue, $saturation, $lightness] = self::toHsl($bg);

        $chroma = $saturation * (1 - abs(2 * $lightness - 1));

        if ($chroma < self::ACCENT_MIN_CHROMA) {
            $hue = self::ACCENT_FALLBACK_HUE;
        }

        return self::accentAtHue($hue);
    }

    /**
     * WCAG contrast ratio between two colors: (L1 + 0.05) / (L2 + 0.05), L1
     * the lighter of the two relative luminances, L2 the darker. 1 = no
     * contrast (identical colors), 21 = maximum (black on white).
     */
    public static function contrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::relativeLuminance($hex1);
        $l2 = self::relativeLuminance($hex2);

        [$lighter, $darker] = $l1 >= $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * WCAG relative luminance: linearize each sRGB channel, then weight by
     * 0.2126 / 0.7152 / 0.0722 (R/G/B) — not a naive channel average, so the
     * light/dark decision above is perceptually reasonable (e.g. green reads
     * as "lighter" than blue at the same 0-255 value).
     */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::toRgb($hex);

        return 0.2126 * self::linearize($r)
            + 0.7152 * self::linearize($g)
            + 0.0722 * self::linearize($b);
    }

    /**
     * Linear interpolation between two hex colors, per sRGB channel (not
     * gamma-corrected — deliberately the simplest, most easily hand-verified
     * option; at the small weights used for cardBackground() the difference
     * from a gamma-correct blend is negligible). $weight 0 returns $from, 1
     * returns $to; out-of-range weights are clamped.
     */
    public static function blend(string $from, string $to, float $weight): string
    {
        $weight = max(0.0, min(1.0, $weight));

        [$r1, $g1, $b1] = self::toRgb($from);
        [$r2, $g2, $b2] = self::toRgb($to);

        $mix = static fn (int $a, int $b): int => (int) round($a + ($b - $a) * $weight);

        return sprintf('#%02x%02x%02x', $mix($r1, $r2), $mix($g1, $g2), $mix($b1, $b2));
    }

    /**
     * Parse a `#rgb` or `#rrggbb` hex color into 0-255 RGB channels.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toRgb(string $hex): array
    {
        $normalized = ltrim(trim($hex), '#');

        if (strlen($normalized) === 3) {
            $normalized = $normalized[0].$normalized[0].$normalized[1].$normalized[1].$normalized[2].$normalized[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Not a valid hex color: [{$hex}].");
        }

        return [
            (int) hexdec(substr($normalized, 0, 2)),
            (int) hexdec(substr($normalized, 2, 2)),
            (int) hexdec(substr($normalized, 4, 2)),
        ];
    }

    /**
     * Convert a hex color to HSL: hue in degrees [0, 360), saturation and
     * lightness each 0-1. The standard textbook conversion — needed by
     * accentFor() to isolate $bg's hue independently of its saturation and
     * lightness, neither of which an accent should just copy.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function toHsl(string $hex): array
    {
        [$r, $g, $b] = self::toRgb($hex);

        // Cast before dividing: int/int is exact-division-returns-int in PHP
        // (e.g. 255/255 === 1, not 1.0), which would silently break the
        // documented all-float return type for whole-number channels.
        $r = (float) $r / 255;
        $g = (float) $g / 255;
        $b = (float) $b / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $lightness];
        }

        $delta = $max - $min;
        $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        if ($max === $r) {
            $hue = 60 * fmod((($g - $b) / $delta), 6);
        } elseif ($max === $g) {
            $hue = 60 * (($b - $r) / $delta + 2);
        } else {
            $hue = 60 * (($r - $g) / $delta + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation, $lightness];
    }

    /**
     * Convert HSL (hue in degrees, saturation/lightness 0-1) back to a
     * lowercase 6-digit hex color — the inverse of toHsl(), used by
     * accentFor() to build a candidate at a chosen hue/saturation/
     * lightness and to test each darkened candidate during its lightness
     * search.
     */
    public static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        if ($saturation === 0.0) {
            $r = $g = $b = $lightness;
        } else {
            $q = $lightness < 0.5
                ? $lightness * (1 + $saturation)
                : $lightness + $saturation - $lightness * $saturation;
            $p = 2 * $lightness - $q;
            $h = $hue / 360;

            $r = self::hueToChannel($p, $q, $h + 1 / 3);
            $g = self::hueToChannel($p, $q, $h);
            $b = self::hueToChannel($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    /** WCAG linearization of a single 0-255 sRGB channel. */
    private static function linearize(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    /** hue2rgb() sector helper from the standard HSL-to-RGB reconstruction. */
    private static function hueToChannel(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }

        if ($t > 1) {
            $t -= 1;
        }

        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }

        if ($t < 1 / 2) {
            return $q;
        }

        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }

    /**
     * Build the accent at a specific hue: the fixed vibrant S/L candidate,
     * darkened — hue and saturation held fixed — via bisection until it
     * clears ACCENT_MIN_CONTRAST against white (#ffffff, the submit
     * button's own fixed, hardcoded label color).
     *
     * Bisection, not a closed-form inverse of the luminance formula:
     * contrast-against-white is monotonically non-increasing in lightness
     * at fixed hue/saturation (verified across the hue wheel), so a simple
     * halving search converges reliably; a closed-form solve would still
     * need to invert the same hue2rgb() sector logic afterward, for no
     * real gain in correctness or simplicity — matches this file's
     * existing preference for simple, obviously-correct code.
     *
     * The search always terminates with a passing result: at L=0 every hue
     * reduces to black regardless of saturation (21:1 against white), so
     * the lower bound always already satisfies the target.
     */
    private static function accentAtHue(float $hue): string
    {
        $candidate = self::fromHsl($hue, self::ACCENT_SATURATION, self::ACCENT_LIGHTNESS);

        if (self::contrastRatio($candidate, '#ffffff') >= self::ACCENT_MIN_CONTRAST) {
            return $candidate;
        }

        $low = 0.0;
        $high = self::ACCENT_LIGHTNESS;

        for ($i = 0; $i < self::ACCENT_LIGHTNESS_SEARCH_ITERATIONS; $i++) {
            $mid = ($low + $high) / 2;
            $midCandidate = self::fromHsl($hue, self::ACCENT_SATURATION, $mid);

            if (self::contrastRatio($midCandidate, '#ffffff') >= self::ACCENT_MIN_CONTRAST) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return self::fromHsl($hue, self::ACCENT_SATURATION, $low);
    }
}
