<?php

declare(strict_types=1);

use JamesGifford\Hold\Hold;
use JamesGifford\Hold\Support\ColorTheme;

/*
 * The tiered appearance config: this template's own override (tested in
 * PagePaletteTest.php/MailPaletteTest.php) > appearance.{pages,mail}.* >
 * appearance.* (shared) > the existing ColorTheme auto-derivation. Only
 * that new config tier is exercised here — the mechanism only needs
 * proving once generically, plus a couple of representative properties
 * end to end, not exhaustively for all nineteen.
 */

// --- Hold::appearance() directly ---------------------------------------

it('falls back to the package default when nothing is configured', function () {
    expect(Hold::appearance('bg', 'pages'))->toBe('#f5f6f8');
    expect(Hold::appearance('accent', 'pages'))->toBeNull();
});

it('lets the shared config value win over the package default', function () {
    config()->set('jamesgifford.hold.appearance.bg', '#4ba69d');

    expect(Hold::appearance('bg', 'pages'))->toBe('#4ba69d');
    expect(Hold::appearance('bg', 'mail'))->toBe('#4ba69d');
});

it('lets a group config value win over the shared value', function () {
    config()->set('jamesgifford.hold.appearance.bg', '#4ba69d');
    config()->set('jamesgifford.hold.appearance.pages.bg', '#111827');

    expect(Hold::appearance('bg', 'pages'))->toBe('#111827');
    expect(Hold::appearance('bg', 'mail'))->toBe('#4ba69d');
});

it('resolves to null, not a made-up value, for a property nothing sets a default for', function () {
    expect(Hold::appearance('muted', 'mail'))->toBeNull();
});

it('resolves card_blend_weight and muted_blend_weight through the same tiers as the colors', function () {
    expect(Hold::appearance('card_blend_weight', 'pages'))->toBeNull();
    expect(Hold::appearance('muted_blend_weight', 'mail'))->toBeNull();

    config()->set('jamesgifford.hold.appearance.card_blend_weight', 0.3);
    expect(Hold::appearance('card_blend_weight', 'pages'))->toBe(0.3);
    expect(Hold::appearance('card_blend_weight', 'mail'))->toBe(0.3);

    config()->set('jamesgifford.hold.appearance.pages.card_blend_weight', 0.7);
    expect(Hold::appearance('card_blend_weight', 'pages'))->toBe(0.7);
    expect(Hold::appearance('card_blend_weight', 'mail'))->toBe(0.3);

    config()->set('jamesgifford.hold.appearance.mail.muted_blend_weight', 0.2);
    expect(Hold::appearance('muted_blend_weight', 'mail'))->toBe(0.2);
});

it('treats a malformed color override as no override, falling through to the next tier', function () {
    config()->set('jamesgifford.hold.appearance.bg', '');
    expect(Hold::appearance('bg', 'pages'))->toBe('#f5f6f8');

    config()->set('jamesgifford.hold.appearance.bg', 'white');
    expect(Hold::appearance('bg', 'pages'))->toBe('#f5f6f8');

    config()->set('jamesgifford.hold.appearance.bg', ['#4ba69d']);
    expect(Hold::appearance('bg', 'pages'))->toBe('#f5f6f8');
});

it('lets a valid shared color win when the more specific group tier is malformed', function () {
    config()->set('jamesgifford.hold.appearance.bg', '#4ba69d');
    config()->set('jamesgifford.hold.appearance.pages.bg', 'not-a-color');

    expect(Hold::appearance('bg', 'pages'))->toBe('#4ba69d');
});

it('treats a malformed blend-weight override as no override', function () {
    config()->set('jamesgifford.hold.appearance.card_blend_weight', 'not-a-number');
    expect(Hold::appearance('card_blend_weight', 'pages'))->toBeNull();

    config()->set('jamesgifford.hold.appearance.card_blend_weight', ['0.5']);
    expect(Hold::appearance('card_blend_weight', 'pages'))->toBeNull();
});

// --- Template integration ------------------------------------------------

it('applies a shared config bg to both a page template and a mail template', function () {
    config()->set('jamesgifford.hold.appearance.bg', '#4ba69d');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-bg: #4ba69d;');
    expect(holdRender('hold::mail.receipt'))->toContain('background:#4ba69d; color:#1a1d24;');
});

it('scopes a page-only bg override so the mail templates keep the shared default', function () {
    config()->set('jamesgifford.hold.appearance.pages.bg', '#111827');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-bg: #111827;');
    expect(holdRender('hold::mail.receipt'))->toContain('background:#f5f6f8; color:#1a1d24;');
});

it('lets an explicit per-template $bg override win over both config tiers', function () {
    config()->set('jamesgifford.hold.appearance.bg', '#4ba69d');
    config()->set('jamesgifford.hold.appearance.pages.bg', '#111827');

    publishEditedPage('prelaunch', ['$bg = null;' => "\$bg = '#ff00ff';"]);

    expect(holdRender('hold::prelaunch'))->toContain('--hold-bg: #ff00ff;');
});

it('scopes a page-only input_border config value to the pages group', function () {
    config()->set('jamesgifford.hold.appearance.pages.input_border', '#ff00ff');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-input-border: #ff00ff;');
});

it('scopes a mail-only muted config value to the mail group', function () {
    config()->set('jamesgifford.hold.appearance.mail.muted', '#ff00ff');

    expect(holdRender('hold::mail.receipt'))->toContain('line-height:1.5; color:#ff00ff;');
});

it('applies shared and group-scoped accent config the same way bg does', function () {
    config()->set('jamesgifford.hold.appearance.accent', '#4ba69d');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-accent: #4ba69d;');
    expect(holdRender('hold::mail.receipt'))->toContain('font-weight:700; color:#4ba69d;');

    config()->set('jamesgifford.hold.appearance.pages.accent', '#111827');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-accent: #111827;');
    expect(holdRender('hold::mail.receipt'))->toContain('font-weight:700; color:#4ba69d;');
});

it('lets a shared card_blend_weight config value drive the shipped templates\' real derivation', function () {
    config()->set('jamesgifford.hold.appearance.card_blend_weight', 0.5);

    $expected = ColorTheme::cardBackground('#f5f6f8', '#1a1d24', 0.5);

    expect(holdRender('hold::prelaunch'))->toContain("--hold-card-bg: {$expected};");
    expect(holdRender('hold::mail.receipt'))->toContain("background:{$expected}; border-radius:12px;");
});

it('lets a mail-only muted_blend_weight config value drive the shipped template\'s real derivation', function () {
    config()->set('jamesgifford.hold.appearance.mail.muted_blend_weight', 0.3);

    $expected = ColorTheme::blend('#f5f6f8', '#1a1d24', 0.3);

    expect(holdRender('hold::mail.receipt'))->toContain("line-height:1.5; color:{$expected};");
});

it('renders the holding page and every mail template without crashing when appearance config is malformed', function () {
    config()->set('jamesgifford.hold.appearance.bg', '');

    expect(holdRender('hold::prelaunch'))->toContain('--hold-bg: #f5f6f8;');
    expect(holdRender('hold::mail.receipt'))->toContain('background:#f5f6f8; color:#1a1d24;');
});
