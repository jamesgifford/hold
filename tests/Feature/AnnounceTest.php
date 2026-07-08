<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\Models\Signup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Tests\Support\Fixtures\CustomLaunchAnnouncement;

it('announces only to subscribed, unnotified signups of the given context', function () {
    Notification::fake();

    $eligible = Signup::factory()->prelaunch()->count(2)->create();
    Signup::factory()->prelaunch()->notified()->create();      // already notified
    Signup::factory()->prelaunch()->unsubscribed()->create();  // unsubscribed
    Signup::factory()->maintenance()->create();                // wrong context

    $result = app(Announcer::class)->send(SignupContext::Prelaunch);

    expect($result->sent)->toBe(2)->and($result->failed)->toBe(0);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);

    foreach ($eligible as $signup) {
        expect($signup->refresh()->notified_at)->not->toBeNull();
    }
});

it('is idempotent — a second run sends nothing', function () {
    Notification::fake();
    Signup::factory()->prelaunch()->count(3)->create();

    app(Announcer::class)->send(SignupContext::Prelaunch);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 3);

    $second = app(Announcer::class)->send(SignupContext::Prelaunch);
    expect($second->sent)->toBe(0);
});

it('never emails an unsubscribed signup', function () {
    Notification::fake();
    Signup::factory()->maintenance()->unsubscribed()->count(3)->create();

    $result = app(Announcer::class)->send(SignupContext::Maintenance);

    expect($result->sent)->toBe(0);
    Notification::assertNothingSent();
});

it('honors a config override of the notification class', function () {
    config()->set('jamesgifford.hold.notifications.classes.launch_announcement', CustomLaunchAnnouncement::class);
    Notification::fake();
    Signup::factory()->prelaunch()->create();

    app(Announcer::class)->send(SignupContext::Prelaunch);

    Notification::assertSentOnDemand(CustomLaunchAnnouncement::class);
});

it('sends the restore announcement for the maintenance context', function () {
    Notification::fake();
    Signup::factory()->maintenance()->count(2)->create();

    app(Announcer::class)->send(SignupContext::Maintenance);

    Notification::assertSentOnDemandTimes(ServiceRestored::class, 2);
});

it('command dry-run reports counts and sends nothing', function () {
    Notification::fake();
    Signup::factory()->prelaunch()->count(2)->create();
    Signup::factory()->maintenance()->count(1)->create();

    $this->artisan('jamesgifford:hold:announce', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('prelaunch')
        ->expectsOutputToContain('maintenance');

    Notification::assertNothingSent();
});

it('command infers the sole context with pending signups', function () {
    Notification::fake();
    Signup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce')
        ->assertSuccessful()
        ->expectsOutputToContain('Announced to 2');

    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);
});

it('announces across multiple chunks for a larger seeded set', function () {
    Notification::fake();
    Signup::factory()->prelaunch()->count(450)->create();

    $result = app(Announcer::class)->send(SignupContext::Prelaunch);

    // chunkById(200) => 3 chunks; every eligible signup emailed exactly once.
    expect($result->sent)->toBe(450)
        ->and(Signup::whereNull('notified_at')->count())->toBe(0);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 450);
});

it('command requires --context when both contexts have pending signups', function () {
    Notification::fake();
    Signup::factory()->prelaunch()->create();
    Signup::factory()->maintenance()->create();

    $this->artisan('jamesgifford:hold:announce')
        ->assertFailed()
        ->expectsOutputToContain('Both contexts');

    Notification::assertNothingSent();
});
