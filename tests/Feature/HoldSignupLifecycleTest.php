<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;

it('sets requested_at on a new signup', function () {
    $this->post('hold/signup', ['email' => 'new@example.com', 'context' => 'prelaunch'])
        ->assertRedirect();

    expect(HoldSignup::first()->requested_at)->not->toBeNull();
});

it('leaves a same-cycle duplicate row byte-identical', function () {
    $this->post('hold/signup', ['email' => 'dupe@example.com', 'context' => 'prelaunch'])
        ->assertRedirect();

    $before = HoldSignup::first()->getAttributes();

    // Advance time and resubmit with a different IP/UA: nothing must change
    // because the row is not yet notified (same cycle).
    Carbon::setTestNow(Carbon::now()->addHour());
    $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])
        ->post('hold/signup', ['email' => 'dupe@example.com', 'context' => 'maintenance'], ['User-Agent' => 'Other/9'])
        ->assertRedirect();
    Carbon::setTestNow();

    expect(HoldSignup::first()->getAttributes())->toBe($before);
    expect(HoldSignup::count())->toBe(1);
});

it('re-arms an already-notified row for a new hold without touching unsubscribed_at', function () {
    $signup = HoldSignup::factory()->prelaunch()->notified()->create([
        'email' => 're@example.com',
        'ip_address' => '1.1.1.1',
        'user_agent' => 'Old/1',
    ]);
    expect($signup->unsubscribed_at)->toBeNull();

    // Re-sign up during a maintenance hold, from a new IP/UA.
    app()->maintenanceMode()->activate(['status' => 503]);
    try {
        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])
            ->post('hold/signup', ['email' => 're@example.com'], ['User-Agent' => 'New/2'])
            ->assertRedirect();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    $signup->refresh();
    expect($signup->notified_at)->toBeNull()
        ->and($signup->context)->toBe(HoldSignupContext::Maintenance)
        ->and($signup->ip_address)->toBe('9.9.9.9')
        ->and($signup->user_agent)->toBe('New/2')
        ->and($signup->requested_at)->not->toBeNull()
        ->and($signup->unsubscribed_at)->toBeNull();
    expect(HoldSignup::count())->toBe(1);
});

it('re-arms an unsubscribed row but never clears unsubscribed_at', function () {
    $signup = HoldSignup::factory()->prelaunch()->notified()->unsubscribed()->create([
        'email' => 'u@example.com',
    ]);
    $originalUnsub = $signup->unsubscribed_at;

    $this->post('hold/signup', ['email' => 'u@example.com', 'context' => 'prelaunch'])
        ->assertRedirect();

    $signup->refresh();
    expect($signup->notified_at)->toBeNull()
        ->and($signup->unsubscribed_at)->not->toBeNull()
        ->and($signup->unsubscribed_at->equalTo($originalUnsub))->toBeTrue();
});

it('notifies a re-armed signup exactly once', function () {
    Notification::fake();

    HoldSignup::factory()->prelaunch()->notified()->create(['email' => 'x@example.com']);

    // Re-arm, then announce twice.
    $this->post('hold/signup', ['email' => 'x@example.com', 'context' => 'prelaunch'])->assertRedirect();

    app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 1);

    app(Announcer::class)->send(HoldSignupContext::Prelaunch);
    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 1);
});
