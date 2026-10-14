<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\HoldState;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');

    // This file's whole point is exercising PrelaunchMode's real enforcement,
    // so opt back into it (the shipped default no-ops during `testing` — see
    // the dedicated cases at the bottom of this file that test THAT default
    // directly, by turning this back off).
    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', true);
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
        ->assertStatus(503)
        ->assertSee('Coming soon')
        ->assertSee('Notify me')
        ->assertDontSee('REAL APP HOMEPAGE');
});

it('intercepts routes that do not even exist while active', function () {
    app(HoldState::class)->enable();

    $this->get('/some/deep/unrouted/path')
        ->assertStatus(503)
        ->assertSee('Coming soon');
});

it('responds 503 with a Retry-After header by default', function () {
    // A machine client that gets held (an unlisted webhook, an uptime check)
    // must read the holding page as a failure to retry, not a delivery.
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600')
        ->assertSee('Coming soon');
});

it('sends the configured prelaunch.retry_after as Retry-After', function () {
    config()->set('jamesgifford.hold.prelaunch.retry_after', 120);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '120');
});

it('omits Retry-After when prelaunch.retry_after is empty', function (mixed $retryAfter) {
    config()->set('jamesgifford.hold.prelaunch.retry_after', $retryAfter);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertHeaderMissing('Retry-After');
})->with([
    'null' => [null],
    'zero' => [0],
]);

it('still sends Retry-After for a published prelaunch config that predates the key', function () {
    // mergeConfigFrom() merges only the top-level key, so an app that published
    // its config before retry_after existed has a `prelaunch` section without it.
    $published = config('jamesgifford.hold.prelaunch');
    unset($published['retry_after']);
    config()->set('jamesgifford.hold.prelaunch', $published);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600');
});

it('returns 200 without Retry-After when status_code is 200', function () {
    config()->set('jamesgifford.hold.prelaunch.status_code', 200);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertOk()
        ->assertHeaderMissing('Retry-After')
        ->assertSee('Coming soon');
});

it('falls back to 503 for an unsupported status_code', function () {
    config()->set('jamesgifford.hold.prelaunch.status_code', 302);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600');
});

it('lets a request carrying a valid bypass cookie reach the real app', function () {
    app(HoldState::class)->enable();

    // The signed preview route (carrying the current activation token) sets the
    // (encrypted) bypass cookie.
    $token = app(HoldState::class)->token();
    $preview = $this->get(URL::signedRoute('hold.preview', ['token' => $token]));
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

    // Forged: withCookie encrypts it like a browser, but the token won't match.
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $this->withCookie($name, 'not-the-current-token')
        ->get('/')
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});

it('honors the configured bypass cookie lifetime', function () {
    config()->set('jamesgifford.hold.prelaunch.bypass_cookie_lifetime_days', 7);
    app(HoldState::class)->enable();

    $token = app(HoldState::class)->token();
    $preview = $this->get(URL::signedRoute('hold.preview', ['token' => $token]));
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

// beforeEach() above opts every other test in this file into real enforcement
// (prelaunch.enforce_in_testing = true) so it can exercise the middleware.
// These cases turn that back off to prove the SHIPPED default — the guard
// against a leftover flag file silently intercepting a consuming app's own
// `testing`-environment test suite — actually behaves as documented.
it('no-ops instead of intercepting while APP_ENV is testing, by default', function () {
    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', false);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertOk()
        ->assertSee('REAL APP HOMEPAGE')
        ->assertDontSee('Coming soon');
});

it('logs a warning when it bypasses an active hold for the testing environment', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'prelaunch.enforce_in_testing') && $context['path'] === '/');

    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', false);
    app(HoldState::class)->enable();

    $this->get('/');
});

it('still intercepts while APP_ENV is testing when enforce_in_testing is explicitly on', function () {
    config()->set('jamesgifford.hold.prelaunch.enforce_in_testing', true);
    app(HoldState::class)->enable();

    $this->get('/')
        ->assertStatus(503)
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});
