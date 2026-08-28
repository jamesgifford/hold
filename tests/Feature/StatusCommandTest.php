<?php

declare(strict_types=1);

use JamesGifford\Hold\HoldState;

afterEach(function () {
    app(HoldState::class)->disable();
});

it('reports no active hold and no token line when nothing is active', function () {
    $this->artisan('jamesgifford:hold:status')
        ->assertSuccessful()
        ->expectsOutputToContain('Active hold: none')
        ->doesntExpectOutputToContain('Bypass token');
});

it('reports a file-backed prelaunch hold with a missing token before preview runs', function () {
    $state = app(HoldState::class);
    $state->ensureDirectory();
    file_put_contents($state->flagPath(), '');

    $this->artisan('jamesgifford:hold:status')
        ->assertSuccessful()
        ->expectsOutputToContain('Active hold: prelaunch (file)')
        ->expectsOutputToContain('Bypass token: MISSING');
});

it('reports a file-backed prelaunch hold with a present token after enable', function () {
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:status')
        ->assertSuccessful()
        ->expectsOutputToContain('Active hold: prelaunch (file)')
        ->expectsOutputToContain('Bypass token: present');
});

it('reports an env-forced prelaunch hold', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);

    $this->artisan('jamesgifford:hold:status')
        ->assertSuccessful()
        ->expectsOutputToContain('Active hold: prelaunch (env)')
        ->expectsOutputToContain('Bypass token: MISSING');
});

it('reports maintenance when it is what is actually active', function () {
    $this->app->maintenanceMode()->activate(['status' => 503]);

    $this->artisan('jamesgifford:hold:status')
        ->assertSuccessful()
        ->expectsOutputToContain('Active hold: maintenance');
});
