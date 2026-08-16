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

it('never emails an unverified signup, and never stamps it', function () {
    // Safe unconditionally, whatever verification.required is currently set
    // to: the stamp-on-create rule (HoldSignupController) means every row
    // is verified-or-pending by construction, so this can never strand a
    // legitimately-captured row.
    Notification::fake();
    HoldSignup::factory()->prelaunch()->unverified()->count(3)->create();

    $result = app(Announcer::class)->send(HoldSignupContext::Prelaunch);

    expect($result->sent)->toBe(0);
    Notification::assertNothingSent();
    expect(HoldSignup::whereNotNull('notified_at')->count())->toBe(0);
});

it('reports a pending count that excludes unverified signups', function () {
    HoldSignup::factory()->prelaunch()->count(2)->create();
    HoldSignup::factory()->prelaunch()->unverified()->create();

    expect(app(Announcer::class)->pending(HoldSignupContext::Prelaunch))->toBe(2);
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

    $this->artisan('jamesgifford:hold:announce', ['--yes' => true])
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

// --- Confirmation before sending --------------------------------------------

it('prompts with the exact pending count before sending, and aborts cleanly on no', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce')
        ->expectsOutputToContain('About to email 2 prelaunch signup(s).')
        ->expectsConfirmation('Send now?', 'no')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(HoldSignup::whereNotNull('notified_at')->count())->toBe(0);
});

it('sends when the confirmation prompt is accepted', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce')
        ->expectsConfirmation('Send now?', 'yes')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);
});

it('--yes skips the confirmation prompt and sends', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce', ['--yes' => true])
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 2);
});

it('refuses to send non-interactively without --yes, sending nothing', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce', ['--no-interaction' => true])
        ->assertFailed()
        ->expectsOutputToContain('--yes');

    Notification::assertNothingSent();
});

// --- Rehearsal: --test=<address> --------------------------------------------

it('sends a rendered test announcement to an arbitrary address, touching no rows', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->count(2)->create();

    $this->artisan('jamesgifford:hold:announce', ['--context' => 'prelaunch', '--test' => 'rehearsal@example.com'])
        ->assertSuccessful()
        ->expectsOutputToContain('Sent a test prelaunch announcement to rehearsal@example.com');

    Notification::assertSentOnDemandTimes(LaunchAnnouncement::class, 1);
    expect(HoldSignup::whereNotNull('notified_at')->count())->toBe(0);
    expect(HoldSignup::count())->toBe(2); // no row created for the test address
    expect(app(Announcer::class)->pending(HoldSignupContext::Prelaunch))->toBe(2);
});

it('works for a --test send when zero signups are pending, given an explicit --context', function () {
    Notification::fake();

    $this->artisan('jamesgifford:hold:announce', ['--context' => 'maintenance', '--test' => 'rehearsal@example.com'])
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(ServiceRestored::class, 1);
    expect(HoldSignup::count())->toBe(0);
});

it('refuses a --test send with no context inferrable and none given', function () {
    Notification::fake();

    $this->artisan('jamesgifford:hold:announce', ['--test' => 'rehearsal@example.com'])
        ->assertFailed()
        ->expectsOutputToContain('--context');

    Notification::assertNothingSent();
});

it('validates the --test address', function () {
    Notification::fake();
    HoldSignup::factory()->prelaunch()->create();

    $this->artisan('jamesgifford:hold:announce', ['--context' => 'prelaunch', '--test' => 'not-an-email'])
        ->assertFailed()
        ->expectsOutputToContain('not a valid email');

    Notification::assertNothingSent();
});

it('rejects combining --test with --dry-run', function () {
    $this->artisan('jamesgifford:hold:announce', ['--dry-run' => true, '--test' => 'rehearsal@example.com'])
        ->assertFailed()
        ->expectsOutputToContain('cannot be combined');
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
