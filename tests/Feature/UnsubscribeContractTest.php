<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;

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
    // HoldSignupCaptured, but the receipt must not send to an unsubscribed address.
    HoldSignup::factory()->prelaunch()->notified()->unsubscribed()->create(['email' => 'u@example.com']);

    $this->post('hold/signup', ['email' => 'u@example.com', 'context' => 'prelaunch'])->assertRedirect();

    Notification::assertNothingSent();
});

it('sends the receipt to a subscribed re-arm', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.send_signup_receipt', true);

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

// --- no unsubscribe surface in emails --------------------------------------

it('renders no unsubscribe link in any public notification', function () {
    $signup = HoldSignup::factory()->prelaunch()->create();

    foreach ([LaunchAnnouncement::class, ServiceRestored::class, HoldSignupReceipt::class] as $class) {
        $mail = (new $class($signup))->toMail($signup);
        // Copy now lives in the self-contained view, so assert against the
        // rendered HTML rather than the (now unused) intro/outro lines.
        $html = strtolower(view($mail->view, $mail->viewData)->render());

        expect($html)->not->toContain('unsubscribe');
    }
});
