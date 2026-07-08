<?php

declare(strict_types=1);

use JamesGifford\Hold\HoldState;

afterEach(function () {
    app(HoldState::class)->disable();
});

it('enables prelaunch mode and prints a signed preview link', function () {
    expect(app(HoldState::class)->isActive())->toBeFalse();

    $this->artisan('jamesgifford:hold:enable')
        ->assertSuccessful()
        ->expectsOutputToContain('Prelaunch mode enabled')
        ->expectsOutputToContain('/hold/preview');

    expect(app(HoldState::class)->isActive())->toBeTrue();
});

it('is idempotent when already enabled', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:enable')
        ->assertSuccessful()
        ->expectsOutputToContain('already active');
});

it('disables prelaunch mode', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('disabled');

    expect(app(HoldState::class)->isActive())->toBeFalse();
});

it('is idempotent when already disabled', function () {
    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('already inactive');
});
