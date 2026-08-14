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

    return holdRender($view);
}

/** Publish an edited copy of a package page template as the winning override. */
/**
 * @param  array<string, string>  $edits
 */
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

// --- maintenance-only extras: eta / apology / contact -----------------------

it('ships maintenance with a default apology and no eta/contact', function () {
    $html = renderPage('hold::maintenance', null);

    expect($html)
        ->toContain('class="hold-apology"')
        ->toContain('inconvenient')
        ->not->toContain('class="hold-eta"')
        ->not->toContain('class="hold-contact"');
});

it('renders the eta line only when copy eta is set', function () {
    publishEditedPage('maintenance', [
        "'eta'            => '',  // plain-language return estimate, e.g. 'We expect to be back by 3pm PT'" => "'eta'            => 'We expect to be back by 3pm PT.',",
    ]);

    expect(renderPage('hold::maintenance', null))
        ->toContain('class="hold-eta"')
        ->toContain('3pm PT');
});

it('renders the contact line unescaped, allowing a mailto link', function () {
    publishEditedPage('maintenance', [
        "'contact'        => '',  // optional; plain text or an <a href=\"mailto:...\">link</a> — rendered UNESCAPED, see markup below" => '\'contact\'        => \'Urgent? <a href="mailto:ops@example.com">ops@example.com</a>\',',
    ]);

    expect(renderPage('hold::maintenance', null))
        ->toContain('class="hold-contact"')
        ->toContain('<a href="mailto:ops@example.com">ops@example.com</a>');
});

it('lets the apology be cleared to empty and disappears entirely', function () {
    publishEditedPage('maintenance', [
        "'apology'        => 'We know this is inconvenient — thank you for your patience.'," => "'apology'        => '',",
    ]);

    expect(renderPage('hold::maintenance', null))->not->toContain('class="hold-apology"');
});

it('collapses eta/apology/contact leaving no wrapper when all are unset', function () {
    publishEditedPage('maintenance', [
        "'apology'        => 'We know this is inconvenient — thank you for your patience.'," => "'apology'        => '',",
    ]);

    $html = renderPage('hold::maintenance', null);

    expect($html)
        ->not->toContain('class="hold-apology"')
        ->not->toContain('class="hold-eta"')
        ->not->toContain('class="hold-contact"');
});
