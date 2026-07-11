<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Jobs\SendAnnouncement;
use JamesGifford\Hold\SignupContext;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');
});

afterEach(function () {
    // Never leave the app down or held between tests. deactivate() doesn't fire
    // events, so it won't re-trigger listeners during teardown.
    $mode = $this->app->maintenanceMode();
    if ($mode->active()) {
        $mode->deactivate();
    }
    app(HoldState::class)->disable();
});

// --- enable maintenance ----------------------------------------------------

it('enable maintenance takes the app down and prints a working secret bypass link', function () {
    expect($this->app->isDownForMaintenance())->toBeFalse();

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'maintenance'])
        ->assertSuccessful()
        ->expectsOutputToContain('Maintenance mode enabled')
        ->expectsOutputToContain('Active hold: maintenance');

    expect($this->app->isDownForMaintenance())->toBeTrue();

    // A non-excepted route is held.
    $this->get('/')->assertStatus(503);

    // The generated secret actually bypasses (visiting /{secret} redirects in).
    $secret = $this->app->maintenanceMode()->data()['secret'];
    expect($secret)->toBeString()->not->toBe('');
    $this->get('/'.$secret)->assertRedirect('/');
});

it('enable maintenance dispatches MaintenanceModeEnabled, matching native down', function () {
    Event::fake([MaintenanceModeEnabled::class]);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'maintenance'])->assertSuccessful();

    // Same observable outcome as `php artisan down`: app is down + event fired.
    expect($this->app->isDownForMaintenance())->toBeTrue();
    Event::assertDispatched(MaintenanceModeEnabled::class);
});

// --- mutual exclusion ------------------------------------------------------

it('refuses to enable maintenance while prelaunch is active', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'maintenance'])
        ->assertFailed()
        ->expectsOutputToContain('already active: prelaunch');

    // The refused mode did not take effect.
    expect($this->app->isDownForMaintenance())->toBeFalse();
});

it('refuses to enable prelaunch while maintenance is active', function () {
    $this->app->maintenanceMode()->activate(['status' => 503]);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])
        ->assertFailed()
        ->expectsOutputToContain('already active: maintenance');

    expect(app(HoldState::class)->isActive())->toBeFalse();
});

// --- disable ---------------------------------------------------------------

it('disable brings the app back up when maintenance is active', function () {
    $this->artisan('jamesgifford:hold:enable', ['mode' => 'maintenance'])->assertSuccessful();
    expect($this->app->isDownForMaintenance())->toBeTrue();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('Maintenance mode disabled')
        ->expectsOutputToContain('Active hold: none');

    expect($this->app->isDownForMaintenance())->toBeFalse();
});

it('disables both holds when both are somehow active', function () {
    // Force the invariant-violating state directly (activate() fires no event,
    // so the self-heal listener does not run).
    $this->app->maintenanceMode()->activate(['status' => 503]);
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('Both holds were active');

    expect($this->app->isDownForMaintenance())->toBeFalse()
        ->and(app(HoldState::class)->isActive())->toBeFalse();
});

it('prefers the maintenance context for the announcement when both were active', function () {
    Queue::fake();
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);

    $this->app->maintenanceMode()->activate(['status' => 503]);
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')->assertSuccessful();

    // `up` schedules the maintenance announcement; the prelaunch one is suppressed.
    Queue::assertPushed(
        SendAnnouncement::class,
        fn (SendAnnouncement $job) => $job->context === SignupContext::Maintenance,
    );
    Queue::assertNotPushed(
        SendAnnouncement::class,
        fn (SendAnnouncement $job) => $job->context === SignupContext::Prelaunch,
    );
});

// --- native-down self-heal -------------------------------------------------

it('auto-disables prelaunch when maintenance is enabled natively', function () {
    app(HoldState::class)->enable();
    expect(app(HoldState::class)->isActive())->toBeTrue();

    // Plain `php artisan down` from deploy tooling fires MaintenanceModeEnabled.
    $this->artisan('down')->assertSuccessful();

    expect(app(HoldState::class)->isActive())->toBeFalse()
        ->and($this->app->isDownForMaintenance())->toBeTrue();
});

// --- 503 shim during real maintenance --------------------------------------

it('the 503 shim renders the maintenance template during real maintenance mode', function () {
    // Make error-view resolution include the package's views root so errors::503
    // resolves to the shim. Laravel's exception handler rebuilds the `errors`
    // namespace from config('view.paths') (each entry's /errors dir) at render
    // time, so that's the hook — mirroring where setup publishes the shim.
    config()->set('view.paths', array_merge(
        [dirname(__DIR__, 2).'/resources/views'],
        config('view.paths', []),
    ));
    config()->set('app.debug', false);

    $this->app->maintenanceMode()->activate(['status' => 503]);

    $this->get('/')
        ->assertStatus(503)
        ->assertSee('right back')
        ->assertSee('Notify me');
});
