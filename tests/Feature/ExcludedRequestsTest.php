<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMiddleware;
use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\HoldState;

/*
 * `except.paths` / `except.routes`: requests that pass straight through BOTH
 * hold modes. The motivating case is a payment webhook — answered with the
 * holding page, the sender records the event as delivered and never retries,
 * so it is silently lost.
 */

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');

    // Webhooks as a consuming app registers them: named, and outside the `web`
    // group so CSRF does not apply.
    Route::post('stripe/webhook', fn () => 'STRIPE HANDLED')->name('cashier.webhook');
    Route::post('webhooks/paddle', fn () => 'PADDLE HANDLED')->name('webhooks.paddle');

    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', true);
});

afterEach(function () {
    $mode = $this->app->maintenanceMode();
    if ($mode->active()) {
        $mode->deactivate();
    }
    app(HoldState::class)->disable();
});

// --- Prelaunch ---------------------------------------------------------------

it('holds an unlisted webhook during prelaunch with a status the sender will retry', function () {
    app(HoldState::class)->enable();

    $this->post('stripe/webhook')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600')
        ->assertDontSee('STRIPE HANDLED');
});

it('lets an excluded path through prelaunch while everything else stays held', function () {
    config()->set('jamesgifford.hold.except.paths', ['stripe/webhook']);
    app(HoldState::class)->enable();

    $this->post('stripe/webhook')
        ->assertOk()
        ->assertSee('STRIPE HANDLED');

    $this->get('/')
        ->assertStatus(503)
        ->assertSee('Coming soon');
});

it('matches excluded paths with wildcards and ignores surrounding slashes', function () {
    config()->set('jamesgifford.hold.except.paths', ['/webhooks/*/']);
    app(HoldState::class)->enable();

    $this->post('webhooks/paddle')->assertOk()->assertSee('PADDLE HANDLED');
    $this->post('stripe/webhook')->assertStatus(503);
});

it('lets an excluded route name through prelaunch', function () {
    config()->set('jamesgifford.hold.except.routes', ['cashier.webhook']);
    app(HoldState::class)->enable();

    $this->post('stripe/webhook')
        ->assertOk()
        ->assertSee('STRIPE HANDLED');

    $this->post('webhooks/paddle')->assertStatus(503);
});

it('matches excluded route names with wildcards', function () {
    config()->set('jamesgifford.hold.except.routes', ['webhooks.*']);
    app(HoldState::class)->enable();

    $this->post('webhooks/paddle')->assertOk()->assertSee('PADDLE HANDLED');
    $this->post('stripe/webhook')->assertStatus(503);
});

it('excludes a named route only for the HTTP methods it accepts', function () {
    // Routing has not run yet when the hold middleware decides, so the route is
    // matched against the request itself — method included. A GET to this
    // POST-only URI is not the webhook, and stays held.
    config()->set('jamesgifford.hold.except.routes', ['cashier.webhook']);
    app(HoldState::class)->enable();

    $this->get('stripe/webhook')
        ->assertStatus(503)
        ->assertSee('Coming soon');
});

it('keeps holding when an excluded route name does not exist', function () {
    config()->set('jamesgifford.hold.except.routes', ['no.such.route']);
    app(HoldState::class)->enable();

    $this->post('stripe/webhook')->assertStatus(503);
});

// --- Maintenance -------------------------------------------------------------

it('holds an unlisted webhook during maintenance', function () {
    app()->maintenanceMode()->activate(['status' => 503]);

    $this->post('stripe/webhook')
        ->assertStatus(503)
        ->assertDontSee('STRIPE HANDLED');
});

it('lets an excluded path through maintenance while everything else stays down', function () {
    config()->set('jamesgifford.hold.except.paths', ['stripe/webhook']);
    app()->maintenanceMode()->activate(['status' => 503]);

    $this->post('stripe/webhook')
        ->assertOk()
        ->assertSee('STRIPE HANDLED');

    $this->get('/')->assertStatus(503);
});

it('lets an excluded route name through maintenance', function () {
    config()->set('jamesgifford.hold.except.routes', ['webhooks.*']);
    app()->maintenanceMode()->activate(['status' => 503]);

    $this->post('webhooks/paddle')
        ->assertOk()
        ->assertSee('PADDLE HANDLED');

    $this->post('stripe/webhook')->assertStatus(503);
});

it('merges excluded paths into the maintenance excluded paths alongside the package prefix', function () {
    config()->set('jamesgifford.hold.except.paths', ['stripe/webhook']);

    expect(app(FrameworkMiddleware::class)->getExcludedPaths())
        ->toContain('stripe/webhook')
        ->toContain('hold')
        ->toContain('hold/*');
});
