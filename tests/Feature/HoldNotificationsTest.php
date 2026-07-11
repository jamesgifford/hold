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
