<?php

declare(strict_types=1);

use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Support\ColorTheme;

/*
 * "Set $bg alone, the rest adapts." publishEditedMail()/renderMailBody() are
 * shared with EmailCopyTest.php.
 */

/**
 * @return array<string, mixed>
 */
function mailPaletteData(string $template): array
{
    return $template === 'receipt' ? [] : ['context' => HoldSignupContext::Prelaunch];
}

it('keeps the default light palette paired with the default text/card/muted/accent', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('background:#f5f6f8; color:#1a1d24;')
            ->toContain('background:#dbdcdf; border-radius:12px;')
            ->toContain('font-weight:700; color:#2a5bc6;')
            ->toContain('font-size:16px; color:#1a1d24;')
            ->toContain('line-height:1.5; color:#6f7277;');
    }
});

it('renders the CTA button with the same accent used for the heading', function () {
    expect(holdRender('hold::mail.announcement', mailPaletteData('announcement')))
        ->toContain('border-radius:8px; background:#2a5bc6;');
});

it('derives light text, a dark-tinted card, and a legible muted for a dark background', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, ["\$bg = '#f5f6f8';" => "\$bg = '#111827';"]);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('background:#111827; color:#f5f6f8;')
            ->toContain('background:#2c3340; border-radius:12px;')
            ->toContain('font-size:16px; color:#f5f6f8;')
            ->toContain('line-height:1.5; color:#9c9fa6;');
    }
});

it('lets an explicit $accent override bypass the derivation', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$accent = null;    // set to override the automatic hue-matched derivation' => "\$accent = '#ff00ff';    // set to override the automatic hue-matched derivation",
        ]);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('font-weight:700; color:#ff00ff;');
    }
});

it('lets an explicit $text override bypass the derivation', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$text = null;      // set to override the automatic light/dark derivation' => "\$text = '#ff00ff';      // set to override the automatic light/dark derivation",
        ]);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('font-size:16px; color:#ff00ff;');
    }
});

it('lets an explicit $card override bypass the derivation', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$card = null;      // set to override the automatic card-background blend' => "\$card = '#ff00ff';      // set to override the automatic card-background blend",
        ]);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('background:#ff00ff; border-radius:12px;');
    }
});

it('lets an explicit $muted override bypass the derivation', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$muted = null;     // set to override the automatic secondary/footnote-text blend' => "\$muted = '#ff00ff';     // set to override the automatic secondary/footnote-text blend",
        ]);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain('line-height:1.5; color:#ff00ff;');
    }
});

it('lets $cardBlendWeight tune how strongly $card blends toward $text', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$cardBlendWeight = $colorTheme::CARD_BLEND_WEIGHT;    // 0-1; how strongly $card blends toward $text' => '$cardBlendWeight = 0.5;    // 0-1; how strongly $card blends toward $text',
        ]);

        $expected = ColorTheme::cardBackground('#f5f6f8', '#1a1d24', 0.5);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain("background:{$expected}; border-radius:12px;");
    }
});

it('lets $mutedBlendWeight tune how strongly $muted blends from $bg toward $text', function () {
    foreach (['announcement', 'team', 'receipt'] as $template) {
        publishEditedMail($template, [
            '$mutedBlendWeight = $colorTheme::MUTED_BLEND_WEIGHT;  // 0-1; how strongly $muted blends from $bg toward $text' => '$mutedBlendWeight = 0.5;  // 0-1; how strongly $muted blends from $bg toward $text',
        ]);

        $expected = ColorTheme::blend('#f5f6f8', '#1a1d24', 0.5);

        expect(holdRender("hold::mail.{$template}", mailPaletteData($template)))
            ->toContain("line-height:1.5; color:{$expected};");
    }
});
