<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\HoldState;

/*
 * Env-forced prelaunch: `prelaunch.forced` (backed by JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED) is an
 * ALTERNATIVE way to activate prelaunch mode, for ephemeral hosting where the
 * flag file does not survive a deploy. These tests drive it through config()
 * directly rather than putenv() — the config key IS the tested surface; env()
 * only ever runs once, inside config/hold.php, at config-merge time (verified
 * separately by the "never calls env() outside the config file" drift guard),
 * so setting the merged config key is equivalent to whatever config:cache
 * would have baked in from the environment.
 */

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');

    // See PrelaunchModeTest for why: the shipped default no-ops enforcement
    // during `testing`. These cases exercise real interception.
    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', true);
});

afterEach(function () {
    app(HoldState::class)->disable();
});

// --- Detection ---------------------------------------------------------

it('is active via the forced config with no flag file present', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $state = app(HoldState::class);

    expect($state->isActive())->toBeTrue()
        ->and($state->isForced())->toBeTrue()
        ->and($state->source())->toBe('env')
        ->and(is_file($state->flagPath()))->toBeFalse();
});

it('is active via the flag file with forced config unset', function () {
    $state = app(HoldState::class);
    $state->enable();

    expect($state->isActive())->toBeTrue()
        ->and($state->isForced())->toBeFalse()
        ->and($state->source())->toBe('file');
});

it('reports no source and is inactive when neither is set', function () {
    $state = app(HoldState::class);

    expect($state->isActive())->toBeFalse()
        ->and($state->isForced())->toBeFalse()
        ->and($state->source())->toBeNull();
});

it('detects the forced state purely through config(), the way config:cache would present it', function () {
    // No putenv() anywhere in this file: JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED is read via env()
    // exactly once, inside config/hold.php, at config-merge time (see the
    // "never calls env() outside the config file" drift guard). Detection
    // here reading config()->set() directly — with no real env var ever
    // touched — proves isForced()/isActive() cannot be looking past the
    // merged config array, which is exactly what config:cache freezes.
    expect(getenv('JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED'))->toBeFalsy();

    config()->set('jamesgifford.hold.prelaunch.forced', true);

    expect(app(HoldState::class)->isForced())->toBeTrue();
});

it('prefers the env source when both the forced config and a flag file are present', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $state = app(HoldState::class);
    $state->ensureDirectory();
    file_put_contents($state->flagPath(), 'a-stray-file-token'.PHP_EOL);

    expect($state->source())->toBe('env');
});

// --- Middleware interaction ---------------------------------------------

it('blocks requests while forced via config, exactly like an active flag file', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $this->get('/')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});

it('lets a bypass cookie carrying the minted env token through', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $token = app(HoldState::class)->reissueToken();

    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $this->withCookie($name, $token)
        ->get('/')
        ->assertOk()
        ->assertSee('REAL APP HOMEPAGE');
});

it('still blocks a stale token cookie under forced mode', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);
    app(HoldState::class)->reissueToken();

    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $this->withCookie($name, 'not-the-current-token')
        ->get('/')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});

// --- enable prelaunch ----------------------------------------------------

it('enable prelaunch reports env-forced state clearly and writes no flag file', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])
        ->assertSuccessful()
        ->expectsOutputToContain('JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED environment variable');

    expect(is_file(app(HoldState::class)->flagPath()))->toBeFalse();
});

it('enable maintenance is refused while prelaunch is env-forced', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'maintenance'])
        ->assertFailed()
        ->expectsOutputToContain('already active: prelaunch');

    expect($this->app->isDownForMaintenance())->toBeFalse();
});

// --- disable ---------------------------------------------------------------

it('disable cannot turn off an env-forced hold and exits non-zero', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $this->artisan('jamesgifford:hold:disable')
        ->assertFailed()
        ->expectsOutputToContain('JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED')
        ->expectsOutputToContain('cannot be disabled')
        ->expectsOutputToContain('Active hold: prelaunch (env)');

    expect(app(HoldState::class)->isActive())->toBeTrue();
});

it('disable removes a stray flag file under env-forced mode while reporting the hold remains', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);
    $state = app(HoldState::class);
    $state->ensureDirectory();
    file_put_contents($state->flagPath(), 'a-stray-file-token'.PHP_EOL);

    $this->artisan('jamesgifford:hold:disable')
        ->assertFailed()
        ->expectsOutputToContain('Removed a stray prelaunch flag file')
        ->expectsOutputToContain('cannot be disabled');

    expect(is_file($state->flagPath()))->toBeFalse()
        ->and($state->isActive())->toBeTrue();
});

it('disable still fully disables a genuinely file-backed hold, unaffected by the env-forced path', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('Prelaunch mode disabled')
        ->expectsOutputToContain('Active hold: none');

    expect(app(HoldState::class)->isActive())->toBeFalse();
});
