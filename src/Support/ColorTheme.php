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

    /** WCAG linearization of a single 0-255 sRGB channel. */
    private static function linearize(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
