<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/*
 * Proves the published-copy-wins / package-fallback contract for every package
 * view. The mechanism is Laravel's own: loadViewsFrom('…/resources/views',
 * 'hold') registers the package as the `hold` namespace, and — at app boot —
 * prepends resources/views/vendor/hold to that namespace's hints when it exists.
 * We reproduce that prepend with View::prependNamespace(), exactly as a fresh
 * app boot would after `vendor:publish`.
 */

/** Point a temp base path at the app so the setup command can publish into it. */
function useTempApp(): string
{
    $root = sys_get_temp_dir().'/hold-views-'.uniqid();
    File::makeDirectory($root, 0777, true);

    app()->setBasePath($root);
    app()->useStoragePath($root.'/storage');
    app()->useDatabasePath($root.'/database');

    return $root;
}

/** Register a published vendor/hold dir as the winning `hold` namespace hint. */
function activatePublishedViews(string $appRoot): string
{
    $vendorHold = $appRoot.'/resources/views/vendor/hold';
    View::prependNamespace('hold', $vendorHold);
    View::flushFinderCache();

    return $vendorHold;
}

afterEach(function () {
    View::flushFinderCache();
});

// --- fallback: nothing published -------------------------------------------

it('renders all three views from the package when no copy is published', function () {
    // No vendor/hold hint registered, so the finder falls back to the package.
    expect(view('hold::prelaunch')->render())
        ->toContain('launching soon')
        ->toContain('--hold-accent');

    expect(view('hold::maintenance')->render())
        ->toContain('right back')
        ->toContain('value="maintenance"');

    // The announcement copy is context-keyed in the template's $copy block.
    expect(view('hold::mail.announcement', ['context' => 'prelaunch'])->render())
        ->toContain('<!-- hold:announcement -->')
        ->toContain('launched!');
});

// --- published copy wins ----------------------------------------------------

it('renders the published, edited copy in preference to the package default', function () {
    $root = useTempApp();

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    $vendorHold = activatePublishedViews($root);

    // Edit the published prelaunch page and the published announcement template.
    File::put($vendorHold.'/prelaunch.blade.php', 'EDITED-PRELAUNCH-COPY');
    File::put($vendorHold.'/mail/announcement.blade.php', 'EDITED-ANNOUNCEMENT-COPY :: {{ $heading }}');
    View::flushFinderCache();

    expect(view('hold::prelaunch')->render())
        ->toContain('EDITED-PRELAUNCH-COPY')
        ->not->toContain('launching soon');   // the package default no longer wins

    expect(view('hold::mail.announcement', ['heading' => 'Launched'])->render())
        ->toContain('EDITED-ANNOUNCEMENT-COPY')
        ->toContain('Launched')                       // passed data still interpolates
        ->not->toContain('<!-- hold:announcement -->');

    File::deleteDirectory($root);
});

// --- delete a published copy → clean fallback ------------------------------

it('falls back to the package copy when a published file is deleted', function () {
    $root = useTempApp();

    $this->artisan('jamesgifford:hold:setup', ['--force' => true])->assertSuccessful();

    $vendorHold = activatePublishedViews($root);
    File::put($vendorHold.'/prelaunch.blade.php', 'EDITED-PRELAUNCH-COPY');
    View::flushFinderCache();
    expect(view('hold::prelaunch')->render())->toContain('EDITED-PRELAUNCH-COPY');

    // Remove the published override; the finder must fall back to the package
    // copy without error (the namespace hint dir still exists but has no file).
    File::delete($vendorHold.'/prelaunch.blade.php');
    View::flushFinderCache();

    expect(view('hold::prelaunch')->render())
        ->toContain('launching soon')
        ->not->toContain('EDITED-PRELAUNCH-COPY');

    File::deleteDirectory($root);
});

// --- 503 shim under real maintenance uses the published, edited template ----

it('renders the published, edited maintenance template via the 503 shim under maintenance mode', function () {
    // Register an edited "published" maintenance template as the winning hint.
    $vendorHold = sys_get_temp_dir().'/hold-vh-'.uniqid();
    File::makeDirectory($vendorHold, 0777, true);
    File::put($vendorHold.'/maintenance.blade.php', '<!doctype html><body>EDITED-MAINTENANCE-COPY value="maintenance"</body>');
    View::prependNamespace('hold', $vendorHold);

    // The exception handler rebuilds the `errors` namespace from view.paths at
    // render time; add the package views root so errors::503 resolves to the
    // shim, which then @includes hold::maintenance (→ our edited override).
    config()->set('view.paths', array_merge(
        [dirname(__DIR__, 2).'/resources/views'],
        config('view.paths', []),
    ));
    config()->set('app.debug', false);
    View::flushFinderCache();

    $this->app->maintenanceMode()->activate(['status' => 503]);

    try {
        $this->get('/')
            ->assertStatus(503)
            ->assertSee('EDITED-MAINTENANCE-COPY')
            ->assertDontSee('right back');   // the package default did not render
    } finally {
        $this->app->maintenanceMode()->deactivate();
        File::deleteDirectory($vendorHold);
    }
});
