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
            ->toContain('--hold-accent: #2563eb;');
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

it('does not derive --hold-accent from the background', function () {
    foreach (['prelaunch', 'maintenance'] as $view) {
        publishEditedPage($view, [
            "\$bg = '#f5f6f8';" => "\$bg = '#111827';",
        ]);

        expect(holdRender("hold::{$view}"))->toContain('--hold-accent: #2563eb;');
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
