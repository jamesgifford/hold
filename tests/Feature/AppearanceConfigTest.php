<?php

declare(strict_types=1);

use JamesGifford\Hold\Hold;

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
