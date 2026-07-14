<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;

/*
 * The header (logo image / wordmark) is driven by file-level variables edited in
 * the published template — exactly like the palette — so these tests take the
 * real package template, apply the one-line edit a developer would make, register
 * it as the winning `hold` namespace copy, and render the actual notification.
 */

/**
 * Write the package announcement template with $edits applied as the winning
 * published copy, and return its rendered HTML for a launch announcement.
 *
 * @param  array<string, string>  $edits  search => replace, applied to the source
 */
function renderAnnouncementWithHeaderEdits(array $edits): string
{
    $source = File::get(dirname(__DIR__, 2).'/resources/views/mail/announcement.blade.php');

    foreach ($edits as $search => $replace) {
        expect($source)->toContain($search);       // guard against a drifted anchor
        $source = str_replace($search, $replace, $source);
    }

    $dir = sys_get_temp_dir().'/hold-header-'.uniqid();
    File::ensureDirectoryExists($dir.'/mail');
    File::put($dir.'/mail/announcement.blade.php', $source);

    View::prependNamespace('hold', $dir);
    View::flushFinderCache();

    try {
        $signup = HoldSignup::factory()->prelaunch()->create();
        $mail = (new LaunchAnnouncement($signup))->toMail($signup);

        return view($mail->view, $mail->viewData)->render();
    } finally {
        File::deleteDirectory($dir);
    }
}

/** Collapse whitespace between tags so structural comparisons ignore indentation. */
function collapseTags(string $html): string
{
    return preg_replace('/>\s+</', '><', $html);
}

it('renders a centered image header when $logoUrl is set', function () {
    config()->set('app.name', 'Acme Co');

    $html = renderAnnouncementWithHeaderEdits([
        '$logoUrl   = null;' => "\$logoUrl   = 'https://cdn.example.com/logo.png';",
    ]);

    expect($html)
        ->toContain('<img ')
        ->toContain('src="https://cdn.example.com/logo.png"')
        ->toContain('width="150"')          // explicit attribute (Outlook ignores max-width)
        ->toContain('height:auto')          // aspect ratio preserved
        ->toContain('alt="Acme Co"');       // visible + used when the client blocks the image
});

it('renders a text wordmark when only $logoName is set (no image)', function () {
    $html = renderAnnouncementWithHeaderEdits([
        '$logoName  = null;' => "\$logoName  = 'Acme Co';",
    ]);

    expect($html)
        ->not->toContain('<img ')
        ->toContain('Acme Co')
        ->toContain('font-weight:800');     // the masthead styling, distinct from the body
});

it('renders no header and no header spacing when both are null (default)', function () {
    config()->set('app.name', 'Acme Co');

    // Unedited package default: both logo variables are null.
    $signup = HoldSignup::factory()->prelaunch()->create();
    $mail = (new LaunchAnnouncement($signup))->toMail($signup);
    $html = view($mail->view, $mail->viewData)->render();

    expect($html)
        ->not->toContain('<img ')
        ->not->toContain('font-weight:800');   // no wordmark masthead either

    // Equivalence with the pre-header layout: the heading is the first element in
    // the card — nothing (not even empty header markup) sits between them.
    expect(collapseTags($html))
        ->toContain('padding:40px 40px 32px;"><h1 ');
});

it('prefers the image over the name when both are set; the name is not a header', function () {
    $html = renderAnnouncementWithHeaderEdits([
        '$logoUrl   = null;' => "\$logoUrl   = 'https://cdn.example.com/logo.png';",
        '$logoName  = null;' => "\$logoName  = 'Acme Co';",
    ]);

    expect($html)
        ->toContain('<img ')
        ->toContain('src="https://cdn.example.com/logo.png"')
        ->toContain('alt="Acme Co"')            // the name still serves as the alt text
        ->not->toContain('font-weight:800');    // ...but is NOT rendered as a wordmark header
});
