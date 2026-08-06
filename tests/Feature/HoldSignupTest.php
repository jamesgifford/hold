<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JamesGifford\Hold\Events\HoldSignupCaptured;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;

it('captures a signup with email, context, ip, and user agent', function () {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post('hold/signup', [
            'email' => 'Person@Example.com',
            'context' => 'prelaunch',
        ], ['User-Agent' => 'PestBrowser/1.0']);

    $response->assertRedirect();
    expect($response->headers->get('location'))->toContain('hold=subscribed');

    $signup = HoldSignup::first();
    expect($signup->email)->toBe('person@example.com')
        ->and($signup->context)->toBe(HoldSignupContext::Prelaunch)
        ->and($signup->ip_address)->toBe('203.0.113.9')
        ->and($signup->user_agent)->toBe('PestBrowser/1.0')
        ->and($signup->requested_at)->not->toBeNull();
});

it('records the maintenance context when the app is down', function () {
    app()->maintenanceMode()->activate(['status' => 503]);

    try {
        $this->post('hold/signup', ['email' => 'down@example.com', 'context' => 'prelaunch'])
            ->assertRedirect();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    // Server-side detection wins over the posted hidden field.
    expect(HoldSignup::first()->context)->toBe(HoldSignupContext::Maintenance);
});

it('treats a duplicate email as a silent success with a single row', function () {
    $this->post('hold/signup', ['email' => 'dupe@example.com', 'context' => 'prelaunch'])
        ->assertRedirect();

    $second = $this->post('hold/signup', ['email' => 'dupe@example.com', 'context' => 'prelaunch']);
    $second->assertRedirect();
    expect($second->headers->get('location'))->toContain('hold=subscribed');

    expect(HoldSignup::where('email', 'dupe@example.com')->count())->toBe(1);
});

it('silently succeeds and stores nothing when the honeypot is filled', function () {
    $response = $this->post('hold/signup', [
        'email' => 'bot@example.com',
        'context' => 'prelaunch',
        'website' => 'http://spam.example',
    ]);

    $response->assertRedirect();
    expect($response->headers->get('location'))->toContain('hold=subscribed');
    expect(HoldSignup::count())->toBe(0);
});

it('reports an invalid email via the query param without storing a row', function () {
    $response = $this->post('hold/signup', ['email' => 'not-an-email', 'context' => 'prelaunch']);

    $response->assertRedirect();
    expect($response->headers->get('location'))->toContain('hold=invalid');
    expect(HoldSignup::count())->toBe(0);
});

it('enforces the per-IP rate limit from config', function () {
    config()->set('jamesgifford.hold.spam.rate_limit_per_minute', 3);

    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('hold/signup', ['email' => "rl{$i}@example.com", 'context' => 'prelaunch'])
            ->assertRedirect();
    }

    // Only the first 3 within the window are stored; the rest silently no-op.
    expect(HoldSignup::count())->toBe(3);
});

it('fires HoldSignupCaptured only for genuinely new rows', function () {
    Event::fake([HoldSignupCaptured::class]);

    $this->post('hold/signup', ['email' => 'new@example.com', 'context' => 'prelaunch']);
    $this->post('hold/signup', ['email' => 'new@example.com', 'context' => 'prelaunch']);

    Event::assertDispatchedTimes(HoldSignupCaptured::class, 1);
});

// --- Redirect target (open-redirect defence) --------------------------------
//
// The endpoint bounces the visitor back to the page they submitted from, taken
// from the Referer header. Referer is attacker-influenced, and while a hold is
// active the holding page renders at EVERY path — so a crafted same-host URL is
// a reachable way to reach this code. The redirect must never leave the origin.

it('never redirects off-site when the referer path begins with a double slash', function () {
    // parse_url() reports host=localhost here — the path, not the host, carries
    // the foreign origin. A "//host" Location is protocol-relative: the browser
    // treats it as an absolute URL and leaves the site.
    $response = $this->withHeaders(['referer' => 'http://localhost//evil.example.com'])
        ->post('hold/signup', ['email' => 'redirect1@example.com', 'context' => 'prelaunch']);

    $response->assertRedirect();
    $location = (string) $response->headers->get('location');

    expect($location)->not->toStartWith('//')
        ->and($location)->not->toContain('evil.example.com');
});

it('never redirects off-site when the referer path begins with a slash-backslash', function () {
    // Browsers normalise "/\" to "//" when resolving a URL, so it is the same
    // attack with one character changed.
    $response = $this->withHeaders(['referer' => 'http://localhost/\evil.example.com'])
        ->post('hold/signup', ['email' => 'redirect2@example.com', 'context' => 'prelaunch']);

    $response->assertRedirect();
    $location = (string) $response->headers->get('location');

    expect($location)->not->toContain('evil.example.com')
        ->and($location)->not->toStartWith('/\\')
        ->and($location)->not->toStartWith('//');
});

it('still returns the visitor to an ordinary same-host path', function () {
    $response = $this->withHeaders(['referer' => 'http://localhost/coming-soon'])
        ->post('hold/signup', ['email' => 'redirect3@example.com', 'context' => 'prelaunch']);

    $response->assertRedirect();
    expect((string) $response->headers->get('location'))
        ->toContain('/coming-soon')
        ->toContain('hold=subscribed');
});

it('falls back to the site root for a cross-host referer', function () {
    $response = $this->withHeaders(['referer' => 'http://evil.example.com/lure'])
        ->post('hold/signup', ['email' => 'redirect4@example.com', 'context' => 'prelaunch']);

    $response->assertRedirect();
    expect((string) $response->headers->get('location'))
        ->not->toContain('evil.example.com')
        ->toContain('hold=subscribed');
});
