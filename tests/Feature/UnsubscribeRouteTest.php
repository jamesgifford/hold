<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Events\HoldSignupUnsubscribed;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Models\HoldSignup;

/*
 * The /unsubscribe route end to end: UnsubscribeController + both the GET
 * (clicked in a browser) and POST (RFC 8058 one-click, no CSRF) paths, both
 * sharing one non-expiring signed URL.
 */

afterEach(function () {
    // HoldState::enable() writes a real flag file, not covered by the
    // transaction rollback other per-test state gets — leaving it active
    // leaks into later tests (e.g. EnableCommand then refuses to enable
    // maintenance, seeing a hold "already" active).
    app(HoldState::class)->disable();
});

function unsubscribeUrlFor(HoldSignup $signup): string
{
    return URL::signedRoute('hold.unsubscribe', ['signup' => $signup->getKey()]);
}

it('unsubscribes a signup via GET and renders the confirmation page', function () {
    $signup = HoldSignup::factory()->create();

    $this->get(unsubscribeUrlFor($signup))->assertOk();

    expect($signup->fresh()->unsubscribed_at)->not->toBeNull();
});

it('unsubscribes via the RFC 8058 one-click POST, without a CSRF token, returning a plain 200', function () {
    $signup = HoldSignup::factory()->create();

    // No CSRF token attached — a real POST would 419 first if the exemption
    // were missing, so this doubles as a regression guard for it.
    $this->post(unsubscribeUrlFor($signup), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertContent('');

    expect($signup->fresh()->unsubscribed_at)->not->toBeNull();
});

it('is idempotent: a second click keeps the original unsubscribed_at', function () {
    $signup = HoldSignup::factory()->create();
    $url = unsubscribeUrlFor($signup);

    $this->get($url)->assertOk();
    $first = $signup->fresh()->unsubscribed_at;

    Carbon::setTestNow(Carbon::now()->addHour());
    $this->get($url)->assertOk();
    Carbon::setTestNow();

    expect($signup->fresh()->unsubscribed_at->equalTo($first))->toBeTrue();
});

it('fires HoldSignupUnsubscribed', function () {
    Event::fake([HoldSignupUnsubscribed::class]);
    $signup = HoldSignup::factory()->create();

    $this->get(unsubscribeUrlFor($signup));

    Event::assertDispatched(
        HoldSignupUnsubscribed::class,
        fn (HoldSignupUnsubscribed $event) => $event->signup->is($signup),
    );
});

it('rejects a signature that no longer matches a tampered signup id with a 403', function () {
    $signup = HoldSignup::factory()->create();
    $other = HoldSignup::factory()->create();
    $tampered = str_replace('signup='.$signup->getKey(), 'signup='.$other->getKey(), unsubscribeUrlFor($signup));

    $this->get($tampered)->assertStatus(403);

    expect($signup->fresh()->unsubscribed_at)->toBeNull()
        ->and($other->fresh()->unsubscribed_at)->toBeNull();
});

it('never expires — a link minted long ago still works', function () {
    $signup = HoldSignup::factory()->create();
    $url = unsubscribeUrlFor($signup);

    Carbon::setTestNow(Carbon::now()->addYears(5));
    $response = $this->get($url);
    Carbon::setTestNow();

    $response->assertOk();
    expect($signup->fresh()->unsubscribed_at)->not->toBeNull();
});

it('404s for an unknown signup id', function () {
    $url = URL::signedRoute('hold.unsubscribe', ['signup' => 999999]);

    $this->get($url)->assertNotFound();
});

it('stays reachable while a prelaunch hold is active', function () {
    $signup = HoldSignup::factory()->create();
    app(HoldState::class)->enable();

    $this->get(unsubscribeUrlFor($signup))->assertOk();

    expect($signup->fresh()->unsubscribed_at)->not->toBeNull();
});

it('stays reachable while a maintenance hold is active', function () {
    $signup = HoldSignup::factory()->create();
    app()->maintenanceMode()->activate(['status' => 503]);

    try {
        $this->get(unsubscribeUrlFor($signup))->assertOk();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($signup->fresh()->unsubscribed_at)->not->toBeNull();
});
