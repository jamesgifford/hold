<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Events\HoldSignupVerified;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Support\Verification;

/*
 * The /verify route end to end: VerifyController + verified.blade.php, reached
 * via the signed, expiring link Verification::url() mints (see
 * HoldNotificationsTest.php for the notification/listener side).
 */

afterEach(function () {
    // HoldState::enable() writes a real flag file, not covered by the
    // transaction rollback other per-test state gets — leaving it active
    // leaks into later tests (e.g. EnableCommand then refuses to enable
    // maintenance, seeing a hold "already" active).
    app(HoldState::class)->disable();
});

function verifyUrlFor(HoldSignup $signup, int $daysFromNow = 7): string
{
    return URL::temporarySignedRoute('hold.verify', Carbon::now()->addDays($daysFromNow), ['signup' => $signup->getKey()]);
}

it('verifies a signup via a valid link and renders the confirmation page', function () {
    $signup = HoldSignup::factory()->unverified()->create();

    $this->get(verifyUrlFor($signup))->assertOk();

    expect($signup->fresh()->verified_at)->not->toBeNull();
});

it('verifies and resubscribes an opted-out row — the only place unsubscribed_at is ever cleared', function () {
    $signup = HoldSignup::factory()->unverified()->unsubscribed()->create();

    $this->get(verifyUrlFor($signup))->assertOk();

    $signup->refresh();
    expect($signup->verified_at)->not->toBeNull()
        ->and($signup->unsubscribed_at)->toBeNull();
});

it('is idempotent: a second click on the same link keeps the original verified_at', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    $url = verifyUrlFor($signup);

    $this->get($url)->assertOk();
    $firstVerifiedAt = $signup->fresh()->verified_at;

    Carbon::setTestNow(Carbon::now()->addHour());
    $this->get($url)->assertOk();
    Carbon::setTestNow();

    expect($signup->fresh()->verified_at->equalTo($firstVerifiedAt))->toBeTrue();
});

it('fires HoldSignupVerified', function () {
    Event::fake([HoldSignupVerified::class]);
    $signup = HoldSignup::factory()->unverified()->create();

    $this->get(verifyUrlFor($signup));

    Event::assertDispatched(
        HoldSignupVerified::class,
        fn (HoldSignupVerified $event) => $event->signup->is($signup),
    );
});

it('rejects an expired link with a 403 and leaves the row unverified', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    $url = verifyUrlFor($signup, daysFromNow: 7);

    Carbon::setTestNow(Carbon::now()->addDays(8));
    $response = $this->get($url);
    Carbon::setTestNow();

    $response->assertStatus(403);
    expect($signup->fresh()->verified_at)->toBeNull();
});

it('rejects a signature that no longer matches a tampered signup id with a 403', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    $other = HoldSignup::factory()->unverified()->create();
    $tampered = str_replace('signup='.$signup->getKey(), 'signup='.$other->getKey(), verifyUrlFor($signup));

    $this->get($tampered)->assertStatus(403);

    expect($signup->fresh()->verified_at)->toBeNull()
        ->and($other->fresh()->verified_at)->toBeNull();
});

it('404s for an unknown signup id', function () {
    $url = URL::temporarySignedRoute('hold.verify', Carbon::now()->addDays(7), ['signup' => 999999]);

    $this->get($url)->assertNotFound();
});

it('honors a custom verification.link_lifetime_days for the minted URL expiry', function () {
    config()->set('jamesgifford.hold.verification.link_lifetime_days', 1);
    $signup = HoldSignup::factory()->unverified()->create();

    $url = Verification::url($signup);

    Carbon::setTestNow(Carbon::now()->addDays(2));
    $response = $this->get($url);
    Carbon::setTestNow();

    $response->assertStatus(403);
});

it('stays reachable while a prelaunch hold is active', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    app(HoldState::class)->enable();

    $this->get(verifyUrlFor($signup))->assertOk();

    expect($signup->fresh()->verified_at)->not->toBeNull();
});

it('stays reachable while a maintenance hold is active', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    app()->maintenanceMode()->activate(['status' => 503]);

    try {
        $this->get(verifyUrlFor($signup))->assertOk();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($signup->fresh()->verified_at)->not->toBeNull();
});
