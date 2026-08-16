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

it('returns whichever candidate contrasts more with the background, ties favoring the first', function () {
    expect(ColorTheme::betterContrast('#f5f6f8', '#1a1d24', '#f5f6f8'))->toBe('#1a1d24');
    expect(ColorTheme::betterContrast('#111827', '#1a1d24', '#f5f6f8'))->toBe('#f5f6f8');
    expect(ColorTheme::betterContrast('#123456', '#abcdef', '#abcdef'))->toBe('#abcdef');
});

it('reimplements textFor() as a pure delegation to betterContrast()', function () {
    expect(ColorTheme::textFor('#f5f6f8'))
        ->toBe(ColorTheme::betterContrast('#f5f6f8', ColorTheme::DARK_TEXT, ColorTheme::LIGHT_TEXT));
    expect(ColorTheme::textFor('#111827'))
        ->toBe(ColorTheme::betterContrast('#111827', ColorTheme::DARK_TEXT, ColorTheme::LIGHT_TEXT));
});

it('derives the documented default alert backgrounds via blend() at the shipped default card background', function () {
    expect(ColorTheme::blend('#dbdcdf', ColorTheme::ALERT_SUCCESS_TINT, ColorTheme::ALERT_SUCCESS_BLEND_WEIGHT))->toBe('#c3d5cd');
    expect(ColorTheme::blend('#dbdcdf', ColorTheme::ALERT_ERROR_TINT, ColorTheme::ALERT_ERROR_BLEND_WEIGHT))->toBe('#d8c9cc');
});

it('picks the light-mode alert text against a light alert background and the dark-mode candidate against a dark one', function () {
    expect(ColorTheme::betterContrast('#c3d5cd', ColorTheme::ALERT_SUCCESS_TEXT_LIGHT, ColorTheme::ALERT_SUCCESS_TEXT_DARK))->toBe(ColorTheme::ALERT_SUCCESS_TEXT_LIGHT);
    expect(ColorTheme::betterContrast('#294041', ColorTheme::ALERT_SUCCESS_TEXT_LIGHT, ColorTheme::ALERT_SUCCESS_TEXT_DARK))->toBe(ColorTheme::ALERT_SUCCESS_TEXT_DARK);
    expect(ColorTheme::betterContrast('#d8c9cc', ColorTheme::ALERT_ERROR_TEXT_LIGHT, ColorTheme::ALERT_ERROR_TEXT_DARK))->toBe(ColorTheme::ALERT_ERROR_TEXT_LIGHT);
    expect(ColorTheme::betterContrast('#3a313c', ColorTheme::ALERT_ERROR_TEXT_LIGHT, ColorTheme::ALERT_ERROR_TEXT_DARK))->toBe(ColorTheme::ALERT_ERROR_TEXT_DARK);
});

it('clears WCAG AA contrast for the dark-mode alert text candidates against their own alert background', function () {
    expect(ColorTheme::contrastRatio(ColorTheme::ALERT_SUCCESS_TEXT_DARK, '#294041'))->toBeGreaterThanOrEqual(4.5);
    expect(ColorTheme::contrastRatio(ColorTheme::ALERT_ERROR_TEXT_DARK, '#3a313c'))->toBeGreaterThanOrEqual(4.5);
});

it('derives an input border that nearly reproduces the previous hardcoded default at the light palette', function () {
    expect(ColorTheme::blend('#f5f6f8', ColorTheme::DARK_TEXT, ColorTheme::INPUT_BORDER_BLEND_WEIGHT))->toBe('#d0d1d4');
});

it('derives a clearly visible input border for a dark input background', function () {
    $border = ColorTheme::blend('#111827', ColorTheme::LIGHT_TEXT, ColorTheme::INPUT_BORDER_BLEND_WEIGHT);

    expect($border)->toBe('#383e4b');
    expect(ColorTheme::contrastRatio($border, '#111827'))->toBeGreaterThan(1.5);
});

it('picks a black shadow for a light background and a white glow for a dark one', function () {
    expect(ColorTheme::betterContrast('#f5f6f8', '#000000', '#ffffff'))->toBe('#000000');
    expect(ColorTheme::betterContrast('#111827', '#000000', '#ffffff'))->toBe('#ffffff');
});

it('derives $muted that nearly reproduces the previous hardcoded contrast ratio at the light default palette', function () {
    $muted = ColorTheme::blend('#f5f6f8', ColorTheme::DARK_TEXT, ColorTheme::MUTED_BLEND_WEIGHT);

    expect($muted)->toBe('#6f7277');
    expect(ColorTheme::contrastRatio($muted, '#f5f6f8'))->toBeGreaterThan(4.4)->toBeLessThan(4.5);
});

it('derives a comfortably legible $muted for a dark background', function () {
    $muted = ColorTheme::blend('#111827', ColorTheme::LIGHT_TEXT, ColorTheme::MUTED_BLEND_WEIGHT);

    expect($muted)->toBe('#9c9fa6');
    expect(ColorTheme::contrastRatio($muted, '#111827'))->toBeGreaterThanOrEqual(4.5);
});
