<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\HoldState;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');
    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', true);
});

afterEach(function () {
    app(HoldState::class)->disable();
});

it('refuses cleanly when prelaunch is not active', function () {
    $this->artisan('jamesgifford:hold:preview')
        ->assertFailed()
        ->expectsOutputToContain('No prelaunch hold is currently active');

    expect(app(HoldState::class)->isActive())->toBeFalse();
});

it('mints a token and prints a working preview link for a file-backed hold, without changing active state', function () {
    $state = app(HoldState::class);
    $state->enable();

    $this->artisan('jamesgifford:hold:preview')
        ->assertSuccessful()
        ->expectsOutputToContain('/hold/preview');

    expect($state->isActive())->toBeTrue()
        ->and($state->source())->toBe('file');

    $this->get(URL::signedRoute('hold.preview', ['token' => $state->token()]))
        ->assertRedirect('/');
});

it('mints a token and prints a working preview link for an env-forced hold', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);
    $state = app(HoldState::class);

    $this->artisan('jamesgifford:hold:preview')
        ->assertSuccessful()
        ->expectsOutputToContain('/hold/preview');

    expect($state->isActive())->toBeTrue()
        ->and($state->source())->toBe('env');

    $this->get(URL::signedRoute('hold.preview', ['token' => $state->token()]))
        ->assertRedirect('/');
});

it('invalidates the previous token when run again', function () {
    $state = app(HoldState::class);
    $state->enable();

    $this->artisan('jamesgifford:hold:preview')->assertSuccessful();
    $oldToken = $state->token();
    $oldUrl = URL::signedRoute('hold.preview', ['token' => $oldToken]);

    $this->artisan('jamesgifford:hold:preview')->assertSuccessful();

    expect($state->token())->not->toBe($oldToken);
    $this->get($oldUrl)->assertForbidden();
});

it('warns when the resolved token store will not persist', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);
    config()->set('cache.default', 'array');

    $this->artisan('jamesgifford:hold:preview')
        ->assertSuccessful()
        ->expectsOutputToContain('will not persist');
});

it('does not warn when the resolved token store is persistent', function () {
    config()->set('jamesgifford.hold.prelaunch.forced', true);
    config()->set('cache.default', 'file');

    $this->artisan('jamesgifford:hold:preview')
        ->assertSuccessful()
        ->doesntExpectOutputToContain('will not persist');
});
