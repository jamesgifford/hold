<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;

// --- the operator command --------------------------------------------------

it('unsubscribes a signup by email via the operator command', function () {
    $signup = HoldSignup::factory()->create(['email' => 'c@example.com']);

    $this->artisan('jamesgifford:hold:unsubscribe', ['email' => 'c@example.com'])
        ->assertSuccessful()
        ->expectsOutputToContain('Unsubscribed c@example.com');

    expect($signup->refresh()->unsubscribed_at)->not->toBeNull();
});

it('resubscribes a signup with --resubscribe', function () {
    $signup = HoldSignup::factory()->unsubscribed()->create(['email' => 'c@example.com']);

    $this->artisan('jamesgifford:hold:unsubscribe', ['email' => 'c@example.com', '--resubscribe' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Resubscribed c@example.com');

    expect($signup->refresh()->unsubscribed_at)->toBeNull();
});

it('reports a not-found email cleanly', function () {
    $this->artisan('jamesgifford:hold:unsubscribe', ['email' => 'nope@example.com'])
        ->assertFailed()
        ->expectsOutputToContain('No signup found for nope@example.com');
});

// --- respected everywhere --------------------------------------------------

it('excludes unsubscribed rows from the signup receipt', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.send_signup_receipt', true);

    // An already-notified, unsubscribed row — re-signing up re-arms it and fires
    // HoldSignupCaptured. The receipt must not send to an unsubscribed address —
    // but the verification email IS deliberately sent (see
    // HoldNotificationsTest's "sends verification to an opted-out address that
    // re-signs up"): it's the one path that can clear the opt-out.
    HoldSignup::factory()->prelaunch()->notified()->unsubscribed()->create(['email' => 'u@example.com']);

    $this->post('hold/signup', ['email' => 'u@example.com', 'context' => 'prelaunch'])->assertRedirect();

    Notification::assertSentOnDemandTimes(HoldSignupReceipt::class, 0);
});

it('sends the receipt to a subscribed re-arm', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.send_signup_receipt', true);
    config()->set('jamesgifford.hold.verification.required', false);

    HoldSignup::factory()->prelaunch()->notified()->create(['email' => 's@example.com']);

    $this->post('hold/signup', ['email' => 's@example.com', 'context' => 'prelaunch'])->assertRedirect();

    Notification::assertSentOnDemand(HoldSignupReceipt::class);
});

it('restores announce eligibility after resubscribe()', function () {
    Notification::fake();

    $signup = HoldSignup::factory()->prelaunch()->unsubscribed()->create();

    // While unsubscribed: excluded from announce.
    app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    Notification::assertNothingSent();

    // After resubscribe: eligible again.
    $signup->resubscribe();
    app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 1);
});

// --- unsubscribe surface in emails ------------------------------------------
//
// As of 1.4.0 the two announcements and the receipt DO carry an opt-out link
// and List-Unsubscribe headers — see EmailCopyTest.php ("renders the
// unsubscribe footer link...") and HoldNotificationsTest.php ("carries
// List-Unsubscribe headers..."). The team notice and the verification email
// deliberately carry neither (also covered there).
