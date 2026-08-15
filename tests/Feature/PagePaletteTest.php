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
            '$accent = null;            // set to override the automatic hue-matched derivation' => "\$accent = '#ff00ff';            // set to override the automatic hue-matched derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-accent: #ff00ff;');
    }
});

it('derives --hold-accent the same way regardless of $text/$cardBg/$inputBg overrides', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#4ba69d';",
            '$text = null;              // set to override the automatic light/dark derivation' => "\$text = '#ff00ff';              // set to override the automatic light/dark derivation",
            '$cardBg = null;            // set to override the automatic card-background blend' => "\$cardBg = '#00ff00';            // set to override the automatic card-background blend",
            '$inputBg = null;           // set to override; defaults to $bg (the "cutout" look)' => "\$inputBg = '#000000';           // set to override; defaults to \$bg (the \"cutout\" look)",
        ]);

        expect(holdRender("hold::{$view}"))
            ->toContain('--hold-accent: '.ColorTheme::accentFor('#4ba69d').';');
    }
});

it('lets $cardBlendWeight tune how strongly the card tints toward $text', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$cardBlendWeight = $colorTheme::CARD_BLEND_WEIGHT;' => '$cardBlendWeight = 0.5;',
        ]);

        $expected = ColorTheme::cardBackground('#f5f6f8', '#1a1d24', 0.5);

        expect(holdRender("hold::{$view}"))->toContain("--hold-card-bg: {$expected};");
    }
});

it('lets an explicit $text override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#111827';",
            '$text = null;              // set to override the automatic light/dark derivation' => "\$text = '#ff00ff';              // set to override the automatic light/dark derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-text: #ff00ff;');
    }
});

it('keeps the alert/border/shadow defaults unchanged (or discloses the shift) at the default light palette', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('--hold-alert-success-bg: #c3d5cd;')
            ->toContain('--hold-alert-success-text: #15803d;')
            ->toContain('--hold-alert-error-bg: #d8c9cc;')
            ->toContain('--hold-alert-error-text: #b91c1c;')
            ->toContain('--hold-input-border: #d0d1d4;')
            ->toContain('--hold-card-shadow-color: 0, 0, 0;');
    }
});

it('derives legible alert colors, a visible input border, and a light shadow-glow for a dark background', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, ["\$bg = '#f5f6f8';" => "\$bg = '#111827';"]);

        expect(holdRender("hold::{$view}"))
            ->toContain('--hold-alert-success-bg: #294041;')
            ->toContain('--hold-alert-success-text: #bbf7d0;')
            ->toContain('--hold-alert-error-bg: #3a313c;')
            ->toContain('--hold-alert-error-text: #fecaca;')
            ->toContain('--hold-input-border: #383e4b;')
            ->toContain('--hold-card-shadow-color: 255, 255, 255;');
    }
});

it('wires the new custom properties into their CSS rules', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('border: 1px solid var(--hold-input-border);')
            ->toContain('box-shadow: 0 10px 40px rgba(var(--hold-card-shadow-color), 0.08);')
            ->toContain('background: var(--hold-alert-success-bg);')
            ->toContain('color: var(--hold-alert-success-text);')
            ->toContain('background: var(--hold-alert-error-bg);')
            ->toContain('color: var(--hold-alert-error-text);');
    }
});

it('lets an explicit $inputBorder override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$inputBorder = null;       // set to override the automatic border-color blend' => "\$inputBorder = '#ff00ff';       // set to override the automatic border-color blend",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-input-border: #ff00ff;');
    }
});

it('lets an explicit $cardShadowColor override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$cardShadowColor = null;   // set to override the automatic light-shadow/dark-glow choice' => "\$cardShadowColor = '#ff00ff';   // set to override the automatic light-shadow/dark-glow choice",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-card-shadow-color: 255, 0, 255;');
    }
});

it('lets an explicit $alertSuccessBg override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$alertSuccessBg = null;    // set to override the automatic success-alert background blend' => "\$alertSuccessBg = '#ff00ff';    // set to override the automatic success-alert background blend",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-alert-success-bg: #ff00ff;');
    }
});

it('lets an explicit $alertSuccessText override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$alertSuccessText = null;  // set to override the automatic success-alert text derivation' => "\$alertSuccessText = '#ff00ff';  // set to override the automatic success-alert text derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-alert-success-text: #ff00ff;');
    }
});

it('lets an explicit $alertErrorBg override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$alertErrorBg = null;      // set to override the automatic error-alert background blend' => "\$alertErrorBg = '#ff00ff';      // set to override the automatic error-alert background blend",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-alert-error-bg: #ff00ff;');
    }
});

it('lets an explicit $alertErrorText override bypass the derivation', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            '$alertErrorText = null;    // set to override the automatic error-alert text derivation' => "\$alertErrorText = '#ff00ff';    // set to override the automatic error-alert text derivation",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-alert-error-text: #ff00ff;');
    }
});
