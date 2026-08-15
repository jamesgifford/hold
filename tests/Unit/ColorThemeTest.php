<?php

declare(strict_types=1);

use JamesGifford\Hold\Support\ColorTheme;

it('parses 6-digit and 3-digit hex, with or without a leading #', function () {
    expect(ColorTheme::toRgb('#f5f6f8'))->toBe([245, 246, 248]);
    expect(ColorTheme::toRgb('f5f6f8'))->toBe([245, 246, 248]);
    expect(ColorTheme::toRgb('#fff'))->toBe([255, 255, 255]);
});

it('rejects an invalid hex color', function () {
    ColorTheme::toRgb('not-a-color');
})->throws(InvalidArgumentException::class);

it('computes canonical WCAG relative luminance for black and white', function () {
    expect(ColorTheme::relativeLuminance('#000000'))->toBe(0.0);
    expect(ColorTheme::relativeLuminance('#ffffff'))->toBe(1.0);
});

it('computes the textbook 21:1 contrast ratio for black on white', function () {
    expect(ColorTheme::contrastRatio('#000000', '#ffffff'))->toBe(21.0);
});

it('picks dark text for a light background and light text for a dark one', function () {
    expect(ColorTheme::textFor('#f5f6f8'))->toBe(ColorTheme::DARK_TEXT);
    expect(ColorTheme::textFor('#111827'))->toBe(ColorTheme::LIGHT_TEXT);
});

it('blends toward the "to" color proportionally to weight, clamped to 0-1', function () {
    expect(ColorTheme::blend('#000000', '#ffffff', 0.0))->toBe('#000000');
    expect(ColorTheme::blend('#000000', '#ffffff', 1.0))->toBe('#ffffff');
    expect(ColorTheme::blend('#000000', '#ffffff', 0.5))->toBe('#808080');
    expect(ColorTheme::blend('#000000', '#ffffff', 2.0))->toBe('#ffffff');
});

it('derives a card background distinct from both the background and the text', function () {
    $card = ColorTheme::cardBackground('#f5f6f8', '#1a1d24');

    expect($card)->not->toBe('#f5f6f8')->not->toBe('#1a1d24');
});

it('derives the documented default card background at the default blend weight', function () {
    expect(ColorTheme::cardBackground('#f5f6f8', '#1a1d24'))->toBe('#dbdcdf');
});

it('round-trips a fully saturated hue through RGB -> HSL -> RGB', function () {
    expect(ColorTheme::toHsl('#ff0000'))->toBe([0.0, 1.0, 0.5]);
    expect(ColorTheme::fromHsl(0.0, 1.0, 0.5))->toBe('#ff0000');
});

it('converts HSL back to hex via the standard hue2rgb reconstruction', function () {
    expect(ColorTheme::fromHsl(210.0, 0.5, 0.4))->toBe('#336699');
    expect(ColorTheme::fromHsl(0.0, 0.0, 0.5))->toBe('#808080');
});

it('falls back to a fixed hue for near-gray backgrounds instead of trusting a noisy extracted hue', function () {
    $fallback = ColorTheme::accentFor('#f5f6f8');

    expect(ColorTheme::accentFor('#f6f5f4'))->toBe($fallback);
    expect(ColorTheme::accentFor('#f4f7f9'))->toBe($fallback);
});

it('derives an accent that shares a saturated background\'s hue', function () {
    [$bgHue] = ColorTheme::toHsl('#4ba69d');
    [$accentHue] = ColorTheme::toHsl(ColorTheme::accentFor('#4ba69d'));

    expect(abs($accentHue - $bgHue))->toBeLessThan(1.0);
});

it('always clears the WCAG contrast target against white, across the hue wheel', function () {
    foreach (range(0, 345, 15) as $hue) {
        $bg = ColorTheme::fromHsl((float) $hue, 0.5, 0.5);
        $accent = ColorTheme::accentFor($bg);

        expect(ColorTheme::contrastRatio($accent, '#ffffff'))
            ->toBeGreaterThanOrEqual(ColorTheme::ACCENT_MIN_CONTRAST);
    }
});

it('derives the documented default accent at the low-chroma fallback hue', function () {
    expect(ColorTheme::accentFor('#f5f6f8'))->toBe('#2a5bc6');
});
