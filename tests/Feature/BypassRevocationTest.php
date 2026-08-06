<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use JamesGifford\Hold\HoldState;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');
});

afterEach(function () {
    app(HoldState::class)->disable();
});

/**
 * Grab the encrypted bypass cookie a preview response sets, for later replay.
 *
 * @param  TestResponse<Response>  $response
 */
function bypassCookieValue(TestResponse $response): ?string
{
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === $name);

    return $cookie?->getValue();
}

it('mints a fresh token on each activation', function () {
    $state = app(HoldState::class);

    $state->enable();
    $first = $state->token();

    $state->disable();
    expect($state->token())->toBeNull();

    $state->enable();
    $second = $state->token();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($second)->not->toBe($first);
});

it('revokes old preview links and cookies when the hold is re-enabled', function () {
    $state = app(HoldState::class);
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');

    // First activation: mint a working link + cookie.
    $state->enable();
    $oldToken = $state->token();
    $oldUrl = URL::signedRoute('hold.preview', ['token' => $oldToken]);

    $oldPreview = $this->get($oldUrl);
    $oldPreview->assertRedirect('/');
    $oldCookieValue = bypassCookieValue($oldPreview);
    expect($oldCookieValue)->not->toBeNull();

    // Re-activation issues a new token.
    $state->disable();
    $state->enable();
    expect($state->token())->not->toBe($oldToken);

    // The OLD signed link still has a valid signature but a stale token — it is
    // rejected exactly like a forged link (403), and sets no cookie.
    $stale = $this->get($oldUrl);
    $stale->assertForbidden();
    expect(bypassCookieValue($stale))->toBeNull();

    // The OLD cookie no longer bypasses — the holding page is shown.
    $this->withUnencryptedCookie($name, $oldCookieValue)
        ->get('/')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');

    // The freshly printed link works and its cookie bypasses.
    $newUrl = URL::signedRoute('hold.preview', ['token' => $state->token()]);
    $newPreview = $this->get($newUrl);
    $newPreview->assertRedirect('/');

    $this->withUnencryptedCookie($name, bypassCookieValue($newPreview))
        ->get('/')
        ->assertOk()
        ->assertSee('REAL APP HOMEPAGE');
});

it('rejects a preview link whose token does not match the current activation', function () {
    app(HoldState::class)->enable();

    // Valid signature, wrong token → indistinguishable from a bad signature.
    $this->get(URL::signedRoute('hold.preview', ['token' => 'some-other-token']))
        ->assertForbidden();
});

it('does not crash and grants no bypass when the flag file is empty', function () {
    $state = app(HoldState::class);
    $state->ensureDirectory();
    file_put_contents($state->flagPath(), '');

    expect($state->isActive())->toBeTrue()
        ->and($state->token())->toBeNull();

    // Middleware still renders the holding page, no error.
    $this->get('/')->assertOk()->assertSee('Coming soon');

    // No cookie can satisfy a tokenless activation.
    $name = config('jamesgifford.hold.prelaunch.bypass_cookie_name');
    $this->withCookie($name, 'anything')
        ->get('/')
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertDontSee('REAL APP HOMEPAGE');
});
