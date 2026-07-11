<?php

declare(strict_types=1);

use JamesGifford\Hold\HoldState;

afterEach(function () {
    app(HoldState::class)->disable();
});

it('enables prelaunch mode, prints a signed preview link, and reports status', function () {
    expect(app(HoldState::class)->isActive())->toBeFalse();

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])
        ->assertSuccessful()
        ->expectsOutputToContain('Prelaunch')
        ->expectsOutputToContain('/hold/preview')
        ->expectsOutputToContain('Active hold: prelaunch');

    expect(app(HoldState::class)->isActive())->toBeTrue();
});

it('rejects an unknown mode', function () {
    $this->artisan('jamesgifford:hold:enable', ['mode' => 'bogus'])
        ->assertFailed()
        ->expectsOutputToContain("Unknown mode 'bogus'");
});

it('refuses to enable a second hold while prelaunch is already active', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])
        ->assertFailed()
        ->expectsOutputToContain('already active: prelaunch');

    // Still exactly the original hold.
    expect(app(HoldState::class)->isActive())->toBeTrue();
});

it('disables prelaunch mode and reports status', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('Prelaunch mode disabled')
        ->expectsOutputToContain('Active hold: none');

    expect(app(HoldState::class)->isActive())->toBeFalse();
});

it('reports cleanly when disable runs with no hold active', function () {
    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('No hold is active');
});
