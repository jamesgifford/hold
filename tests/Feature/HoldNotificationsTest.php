<?php

declare(strict_types=1);

use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Jobs\SendAnnouncement;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\TeamHoldEnabled;

afterEach(function () {
    app(HoldState::class)->disable();
});

// --- Team notice -----------------------------------------------------------

it('notifies the team when maintenance mode is enabled and addresses are set', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.team_addresses', ['team@example.com']);

    event(new MaintenanceModeEnabled);

    Notification::assertSentOnDemand(TeamHoldEnabled::class);
});

it('sends no team notice when no addresses are configured', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.team_addresses', []);

    event(new MaintenanceModeEnabled);

    Notification::assertNothingSent();
});

it('notifies the team when the prelaunch enable command runs', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.team_addresses', ['team@example.com']);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])->assertSuccessful();

    Notification::assertSentOnDemand(TeamHoldEnabled::class);
});

// --- Auto-announce dispatch + delay ----------------------------------------

it('dispatches a delayed restore announcement on up only when auto-announce is on', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-08 12:00:00');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 10);

    event(new MaintenanceModeDisabled);

    Queue::assertPushed(SendAnnouncement::class, function (SendAnnouncement $job) {
        return $job->context === HoldSignupContext::Maintenance
            && $job->delay instanceof Carbon
            && $job->delay->equalTo(Carbon::parse('2026-07-08 12:10:00'));
    });

    Carbon::setTestNow();
});

it('does not auto-dispatch when auto-announce is off', function () {
    Queue::fake();
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', false);

    event(new MaintenanceModeDisabled);

    Queue::assertNothingPushed();
});

it('schedules the prelaunch announcement on disable when auto-announce is on', function () {
    Queue::fake();
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->expectsOutputToContain('Launch announcement scheduled');

    Queue::assertPushed(
        SendAnnouncement::class,
        fn (SendAnnouncement $job) => $job->context === HoldSignupContext::Prelaunch,
    );
});

// --- Auto-announce vs. a queue that cannot delay ---------------------------
//
// Illuminate\Queue\SyncQueue::later() forwards straight to push(), discarding
// the delay. On a sync connection the change-of-mind window would silently be
// zero and the announcement would go out — irrevocably — the instant the hold
// ends. Refuse to dispatch instead, and say so.

it('refuses to auto-announce when the default queue connection cannot delay', function () {
    Queue::fake();
    config()->set('queue.default', 'sync');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 10);
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('tells the operator rather than claiming a window it cannot honour', function () {
    Queue::fake();
    config()->set('queue.default', 'sync');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 10);
    app(HoldState::class)->enable();

    $this->artisan('jamesgifford:hold:disable')
        ->assertSuccessful()
        ->doesntExpectOutputToContain('Launch announcement scheduled')
        ->expectsOutputToContain('cannot delay jobs')
        ->expectsOutputToContain('jamesgifford:hold:announce');
});

it('refuses on the native up path too, not just the disable command', function () {
    Queue::fake();
    config()->set('queue.default', 'sync');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 10);

    event(new MaintenanceModeDisabled);

    Queue::assertNothingPushed();
});

it('still auto-announces on a sync queue when the delay is zero', function () {
    // A zero delay means there is no change-of-mind window by design, so a sync
    // connection loses nothing and the dispatch should go ahead.
    Queue::fake();
    config()->set('queue.default', 'sync');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 0);

    event(new MaintenanceModeDisabled);

    Queue::assertPushed(SendAnnouncement::class);
});

it('auto-announces normally on a connection that can delay', function () {
    Queue::fake();
    config()->set('queue.default', 'database');
    config()->set('jamesgifford.hold.notifications.auto_announce_on_up', true);
    config()->set('jamesgifford.hold.notifications.announce_delay_minutes', 10);

    event(new MaintenanceModeDisabled);

    Queue::assertPushed(SendAnnouncement::class);
});

// --- Delayed job change-of-mind guard --------------------------------------

it('aborts the delayed job silently when the hold is active again', function () {
    Notification::fake();
    HoldSignup::factory()->maintenance()->count(2)->create();

    app()->maintenanceMode()->activate(['status' => 503]);

    try {
        $result = (new SendAnnouncement(HoldSignupContext::Maintenance))
            ->handle(app(Announcer::class), app(HoldState::class));
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($result->skipped)->toBeTrue();
    Notification::assertNothingSent();
});

it('runs the delayed job when the hold is no longer active', function () {
    Notification::fake();
    HoldSignup::factory()->maintenance()->count(2)->create();

    $result = (new SendAnnouncement(HoldSignupContext::Maintenance))
        ->handle(app(Announcer::class), app(HoldState::class));

    expect($result->skipped)->toBeFalse()
        ->and($result->sent)->toBe(2);
});

// --- Resilience to a config published before a key existed -----------------
//
// mergeConfigFrom() is a SHALLOW array_merge on the `jamesgifford.hold` key, so
// an app's published config file replaces the package's whole `notifications`
// array rather than merging into it. A config published before a key existed
// therefore leaves that key undefined at runtime — which must degrade to the
// package default, not to `new ''`.

it('falls back to the package team notification when the config omits classes', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.classes', null);
    config()->set('jamesgifford.hold.notifications.team_addresses', ['team@example.com']);

    $this->artisan('jamesgifford:hold:enable', ['mode' => 'prelaunch'])->assertSuccessful();

    Notification::assertSentOnDemand(TeamHoldEnabled::class);
});

it('falls back to the package announcement when the config omits classes', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.classes', null);
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $result = app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    expect($result->sent)->toBe(2)
        ->and($result->failed)->toBe(0);
    Notification::assertSentOnDemand(LaunchAnnouncement::class);
});

it('falls back to the package receipt when the config omits classes', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.classes', null);
    config()->set('jamesgifford.hold.notifications.send_signup_receipt', true);

    $this->post('hold/signup', ['email' => 'stale@example.com', 'context' => 'prelaunch']);

    Notification::assertSentOnDemand(HoldSignupReceipt::class);
});

// --- Signup receipt --------------------------------------------------------

it('sends a receipt on capture when the receipt option is enabled', function () {
    Notification::fake();
    config()->set('jamesgifford.hold.notifications.send_signup_receipt', true);

    $this->post('hold/signup', ['email' => 'receipt@example.com', 'context' => 'prelaunch']);

    Notification::assertSentOnDemand(HoldSignupReceipt::class);
});

it('sends no receipt on capture by default', function () {
    Notification::fake();

    $this->post('hold/signup', ['email' => 'noreceipt@example.com', 'context' => 'prelaunch']);

    Notification::assertNothingSent();
});
