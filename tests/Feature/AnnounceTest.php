<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\Tests\Support\Fixtures\CustomLaunchAnnouncement;
use JamesGifford\Hold\Tests\Support\Fixtures\ExplodingLaunchAnnouncement;

it('announces only to subscribed, unnotified signups of the given context', function () {
    Notification::fake();

    $eligible = HoldSignup::factory()->prelaunch()->count(2)->create();
    HoldSignup::factory()->prelaunch()->notified()->create();      // already notified
    HoldSignup::factory()->prelaunch()->unsubscribed()->create();  // unsubscribed
    HoldSignup::factory()->maintenance()->create();                // wrong context

    $result = app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    expect($result->sent)->toBe(2)->and($result->failed)->toBe(0);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);

    foreach ($eligible as $signup) {
        expect($signup->refresh()->notified_at)->not->toBeNull();
    }
});

it('is idempotent — a second run sends nothing', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(3)->create();

    app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 3);

    $second = app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    expect($second->sent)->toBe(0);
});

it('never emails an unsubscribed signup', function () {
    Notification::fake();
    HoldSignup::factory()->maintenance()->unsubscribed()->count(3)->create();

    $result = app(Announcer::class)->send(HoldSignupContext::Maintenance);

    expect($result->sent)->toBe(0);
    Notification::assertNothingSent();
});

it('honors a config override of the notification class', function () {
    config()->set('jamesgifford.hold.notifications.classes.launch_announcement', CustomLaunchAnnouncement::class);
    Notification::fake();
    HoldSignup::factory()->prelaunch()->create();

    app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    Notification::assertSentOnDemand(CustomLaunchAnnouncement::class);
});

it('sends the restore announcement for the maintenance context', function () {
    Notification::fake();
    HoldSignup::factory()->maintenance()->count(2)->create();

    app(Announcer::class)->send(HoldSignupContext::Maintenance);

    Notification::assertSentOnDemandTimes(ServiceRestored::class, 2);
});

it('command dry-run reports counts and sends nothing', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();
    HoldSignup::factory()->maintenance()->count(1)->create();

    $this->artisan('jamesgifford:hold:announce', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('prelaunch')
        ->expectsOutputToContain('maintenance');

    Notification::assertNothingSent();
});

it('command infers the sole context with pending signups', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce')
        ->assertSuccessful()
        ->expectsOutputToContain('Announced to 2');

    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);
});

it('announces across multiple chunks for a larger seeded set', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(450)->create();

    $result = app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    // chunkById(200) => 3 chunks; every eligible signup emailed exactly once.
    expect($result->sent)->toBe(450)
        ->and(HoldSignup::whereNull('notified_at')->count())->toBe(0);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 450);
});

it('command requires --context when both contexts have pending signups', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->create();
    HoldSignup::factory()->maintenance()->create();

    $this->artisan('jamesgifford:hold:announce')
        ->assertFailed()
        ->expectsOutputToContain('Both contexts');

    Notification::assertNothingSent();
});

// --- Per-recipient failure path ---------------------------------------------

it('records a failed send and completes the run instead of breaking in the error path', function () {
    // The catch block logs the context alongside the signup id. If that logging
    // itself misbehaves, a single bad recipient takes down the whole run — the
    // one moment the package most needs to keep going.
    Notification::fake();
    config()->set(
        'jamesgifford.hold.notifications.classes.launch_announcement',
        ExplodingLaunchAnnouncement::class,
    );
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $result = app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    expect($result->failed)->toBe(2)
        ->and($result->sent)->toBe(0);

    // Nothing was marked notified, so a later run can retry them.
    expect(HoldSignup::whereNotNull('notified_at')->count())->toBe(0);
});
