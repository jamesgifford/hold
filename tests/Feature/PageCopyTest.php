<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/*
 * The holding pages' wording now lives in each file's top-of-file $copy block,
 * including the two form-state messages. These tests render the real templates
 * (driving the ?hold state through the request) and, for the override cases,
 * apply the one-line edit a developer would make to the published copy.
 */

/** Render a holding page with a given ?hold state (null = neutral, no alert). */
function renderPage(string $view, ?string $status): string
{
    request()->merge(['hold' => $status]);

    return view($view)->render();
}

/** Publish an edited copy of a package page template as the winning override. */
function publishEditedPage(string $view, array $edits): void
{
    $source = File::get(dirname(__DIR__, 2)."/resources/views/{$view}.blade.php");

    foreach ($edits as $search => $replace) {
        expect($source)->toContain($search);
        $source = str_replace($search, $replace, $source);
    }

    $dir = sys_get_temp_dir().'/hold-pagecopy-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir."/{$view}.blade.php", $source);

    View::prependNamespace('hold', $dir);
    View::flushFinderCache();
}

// --- fresh install: default wording (prelaunch) ----------------------------

it('renders the prelaunch page default wording, incl. both form states', function () {
    $neutral = renderPage('hold::prelaunch', null);

    // {{ }} escapes apostrophes, so match apostrophe-free fragments.
    expect($neutral)
        ->toContain('<title>Launching soon</title>')
        ->toContain('Coming soon')
        ->toContain('launching soon')
        ->toContain('let you know the moment')
        ->toContain('Email address')
        ->toContain('placeholder="you@example.com"')
        ->toContain('Notify me')
        ->toContain('Unsubscribe anytime')
        // Neither state message renders without the matching ?hold value.
        ->not->toContain('in touch when we launch')
        ->not->toContain('valid email address');

    expect(renderPage('hold::prelaunch', 'subscribed'))->toContain('in touch when we launch');
    expect(renderPage('hold::prelaunch', 'invalid'))->toContain('valid email address');
});

// --- fresh install: default wording (maintenance) --------------------------

it('renders the maintenance page default wording, incl. both form states', function () {
    $neutral = renderPage('hold::maintenance', null);

    expect($neutral)
        ->toContain('<title>Temporarily down for maintenance</title>')
        ->toContain('Maintenance')
        ->toContain('right back')
        ->toContain('making some improvements')
        ->toContain('Notify me')
        ->toContain('Unsubscribe anytime')
        ->not->toContain('email you the moment')
        ->not->toContain('valid email address');

    expect(renderPage('hold::maintenance', 'subscribed'))->toContain('email you the moment');
    expect(renderPage('hold::maintenance', 'invalid'))->toContain('valid email address');
});

// --- edited $copy shows through, all states (prelaunch) --------------------

it('renders edited prelaunch $copy, including the success and error states', function () {
    publishEditedPage('prelaunch', [
        'launching soon' => 'EDITED-HEADING',
        'in touch when we launch' => 'EDITED-SUCCESS',
        'Please enter a valid email' => 'EDITED-ERROR',
    ]);

    expect(renderPage('hold::prelaunch', null))->toContain('EDITED-HEADING');
    expect(renderPage('hold::prelaunch', 'subscribed'))->toContain('EDITED-SUCCESS');
    expect(renderPage('hold::prelaunch', 'invalid'))->toContain('EDITED-ERROR');
});

// --- edited $copy shows through, all states (maintenance) ------------------

it('renders edited maintenance $copy, including the success and error states', function () {
    publishEditedPage('maintenance', [
        'right back' => 'EDITED-HEADING',
        'email you the moment' => 'EDITED-SUCCESS',
        'Please enter a valid email' => 'EDITED-ERROR',
    ]);

    expect(renderPage('hold::maintenance', null))->toContain('EDITED-HEADING');
    expect(renderPage('hold::maintenance', 'subscribed'))->toContain('EDITED-SUCCESS');
    expect(renderPage('hold::maintenance', 'invalid'))->toContain('EDITED-ERROR');
});
