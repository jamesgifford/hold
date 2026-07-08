<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\HoldState;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');
});

afterEach(function () {
    app(HoldState::class)->disable();
});

it('is a transparent no-op when prelaunch is inactive', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('REAL APP HOMEPAGE');
});

it('renders the holding page for any route when prelaunch is active', function () {
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertSee('Notify me')
        ->assertDontSee('REAL APP HOMEPAGE');
});

it('intercepts routes that do not even exist while active', function () {
    app(HoldState::class)->enable();

    $this->get('/some/deep/unrouted/path')
        ->assertOk()
        ->assertSee('Coming soon');
});

it('returns the configured status code for the holding page', function () {
    config()->set('jamesgifford.hold.prelaunch.status_code', 503);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertSee('Coming soon');
});

it('lets a request carrying a valid bypass cookie reach the real app', function () {
    app(HoldState::class)->enable();

    // The signed preview route sets the (encrypted) bypass cookie.
    $preview = $this->get(URL::signedRoute('hold.preview'));
    $preview->assertRedirect('/');

    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $cookie = collect($preview->headers->getCookies())
        ->first(fn ($c) => $c->getName() === $name);

    expect($cookie)->not->toBeNull();

    // Replay it verbatim (it is already encrypted) — the middleware decrypts it.
    $this->withUnencryptedCookie($name, $cookie->getValue())
        ->get('/')
        ->assertOk()
        ->assertSee('REAL APP HOMEPAGE');
});

it('still shows the holding page for an absent or forged bypass cookie', function () {
    app(HoldState::class)->enable();

    // Absent.
    $this->get('/')->assertSee('Coming soon');

    // Forged: withCookie encrypts it like a browser, but the marker won't match.
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $this->withCookie($name, 'not-the-marker')
        ->get('/')
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});

it('honors the configured bypass cookie lifetime', function () {
    config()->set('jamesgifford.hold.prelaunch.bypass_cookie_lifetime_days', 7);
    app(HoldState::class)->enable();

    $preview = $this->get(URL::signedRoute('hold.preview'));
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $cookie = collect($preview->headers->getCookies())
        ->first(fn ($c) => $c->getName() === $name);

    expect($cookie)->not->toBeNull();
    // Expiry is roughly now + 7 days (cookie() uses wall-clock seconds).
    expect(abs($cookie->getExpiresTime() - (time() + 7 * 86400)))->toBeLessThan(120);
});

it('leaves the package routes reachable while active', function () {
    app(HoldState::class)->enable();

    // The signup route is a package route, so the holding page must not block it.
    $this->post('hold/signup', ['email' => 'reachable@example.com'])
        ->assertRedirect();

    $this->assertDatabaseHas('hold_signups', ['email' => 'reachable@example.com']);
});
