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
