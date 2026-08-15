<?php

declare(strict_types=1);

use JamesGifford\Hold\Support\ColorTheme;

/*
 * "Set $bg alone, the rest adapts." publishEditedPage() is shared with
 * PageCopyTest.php.
 */

it('keeps the default light background paired with the default dark text and default accent', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('--hold-bg: #f5f6f8;')
            ->toContain('--hold-text: #1a1d24;')
            ->toContain('--hold-accent: #2a5bc6;');
    }
});

it('derives light text automatically for a dark background', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#111827';",
        ]);

        expect(holdRender("hold::{$view}"))
            ->toContain('--hold-bg: #111827;')
            ->toContain('--hold-text: '.ColorTheme::LIGHT_TEXT.';');
    }
});

it('derives a card background distinct from both the background and the text', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#111827';",
        ]);

        preg_match('/--hold-card-bg:\s*(#[0-9a-fA-F]{6});/', holdRender("hold::{$view}"), $match);

        expect($match)->not->toBeEmpty();
        expect($match[1])
            ->not->toBe('#111827')
            ->not->toBe(ColorTheme::LIGHT_TEXT);
    }
});

it('derives --hold-accent hue-matched to a saturated background', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#4ba69d';",
        ]);

        expect(holdRender("hold::{$view}"))
            ->toContain('--hold-accent: '.ColorTheme::accentFor('#4ba69d').';');
    }
});

it('lets an explicit $accent override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#4ba69d';",
            '$accent = null;   // set to override the automatic hue-matched derivation' => "\$accent = '#ff00ff';   // set to override the automatic hue-matched derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-accent: #ff00ff;');
    }
});

it('derives --hold-accent the same way regardless of $text/$cardBg/$inputBg overrides', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#4ba69d';",
            '$text = null;     // set to override the automatic light/dark derivation' => "\$text = '#ff00ff';     // set to override the automatic light/dark derivation",
            '$cardBg = null;   // set to override the automatic card-background blend' => "\$cardBg = '#00ff00';   // set to override the automatic card-background blend",
            '$inputBg = null;  // set to override; defaults to $bg (the "cutout" look)' => "\$inputBg = '#000000';  // set to override; defaults to \$bg (the \"cutout\" look)",
        ]);

        expect(holdRender("hold::{$view}"))
            ->toContain('--hold-accent: '.ColorTheme::accentFor('#4ba69d').';');
    }
});

it('lets $cardBlendWeight tune how strongly the card tints toward $text', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$cardBlendWeight = \JamesGifford\Hold\Support\ColorTheme::CARD_BLEND_WEIGHT;' => '$cardBlendWeight = 0.5;',
        ]);

        $expected = ColorTheme::cardBackground('#f5f6f8', '#1a1d24', 0.5);

        expect(holdRender("hold::{$view}"))->toContain("--hold-card-bg: {$expected};");
    }
});

it('lets an explicit $text override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#111827';",
            '$text = null;     // set to override the automatic light/dark derivation' => "\$text = '#ff00ff';     // set to override the automatic light/dark derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-text: #ff00ff;');
    }
});
