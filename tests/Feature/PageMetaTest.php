<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/** Publish an edited copy of the prelaunch template as the winning override. */
/**
 * @param  array<string, string>  $edits
 */
function publishEditedPrelaunch(array $edits): void
{
    $source = File::get(dirname(__DIR__, 2).'/resources/views/prelaunch.blade.php');

    foreach ($edits as $search => $replace) {
        expect($source)->toContain($search);
        $source = str_replace($search, $replace, $source);
    }

    $dir = sys_get_temp_dir().'/hold-pagemeta-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/prelaunch.blade.php', $source);

    View::prependNamespace('hold', $dir);
    View::flushFinderCache();
}

it('marks the maintenance page noindex, nofollow', function () {
    expect(holdRender('hold::maintenance'))
        ->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('does not mark the prelaunch page noindex', function () {
    expect(holdRender('hold::prelaunch'))->not->toContain('noindex');
});

// --- prelaunch sharing metadata ---------------------------------------------

it('renders prelaunch title/description/OG from the default $copy', function () {
    $html = holdRender('hold::prelaunch');

    expect($html)
        ->toContain('<title>Launching soon</title>')
        ->toContain('name="description" content="Leave your email')
        ->toContain('property="og:title" content="Launching soon"')
        ->toContain('property="og:description" content="Leave your email')
        ->toContain('property="og:type" content="website"')
        ->toContain('property="og:url"')
        ->toContain('name="twitter:card" content="summary"')
        ->not->toContain('og:image');
});

it('reflects edited copy title/lede in the title/description/OG tags', function () {
    publishEditedPrelaunch([
        "'title'          => 'Launching soon',       // browser tab / <title>" => "'title'          => 'EDITED-TITLE',",
        "'lede'           => 'Leave your email and we\\'ll let you know the moment we\\'re live.'," => "'lede'           => 'EDITED-DESCRIPTION',",
    ]);

    $html = holdRender('hold::prelaunch');

    expect($html)
        ->toContain('<title>EDITED-TITLE</title>')
        ->toContain('name="description" content="EDITED-DESCRIPTION"')
        ->toContain('property="og:title" content="EDITED-TITLE"')
        ->toContain('property="og:description" content="EDITED-DESCRIPTION"');
});

it('renders og:image and summary_large_image when $ogImage is set', function () {
    publishEditedPrelaunch([
        '$ogImage = null;' => "\$ogImage = 'https://example.com/share.png';",
    ]);

    $html = holdRender('hold::prelaunch');

    expect($html)
        ->toContain('property="og:image" content="https://example.com/share.png"')
        ->toContain('name="twitter:card" content="summary_large_image"');
});

it('omits og:image and uses summary when $ogImage is null (default)', function () {
    $html = holdRender('hold::prelaunch');

    expect($html)
        ->not->toContain('og:image')
        ->toContain('name="twitter:card" content="summary"');
});

it('sets og:url to the current URL', function () {
    $html = holdRender('hold::prelaunch');

    expect($html)->toContain('property="og:url" content="'.url()->current().'"');
});

it('does not add sharing metadata to the maintenance page', function () {
    $html = holdRender('hold::maintenance');

    expect($html)
        ->not->toContain('og:title')
        ->not->toContain('og:description')
        ->not->toContain('og:image')
        ->not->toContain('twitter:card')
        ->not->toContain('name="description"');
});
