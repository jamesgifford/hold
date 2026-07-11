<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;

it('casts context to the enum and timestamps to Carbon', function () {
    $signup = HoldSignup::factory()->prelaunch()->create();

    expect($signup->refresh()->context)->toBe(HoldSignupContext::Prelaunch)
        ->and($signup->created_at)->toBeInstanceOf(Carbon::class);
});

it('scopes to not-yet-notified signups', function () {
    HoldSignup::factory()->create();
    HoldSignup::factory()->notified()->create();

    expect(HoldSignup::notNotified()->count())->toBe(1)
        ->and(HoldSignup::count())->toBe(2);
});

it('scopes to still-subscribed signups', function () {
    HoldSignup::factory()->create();
    HoldSignup::factory()->unsubscribed()->create();

    expect(HoldSignup::subscribed()->count())->toBe(1);
});

it('scopes by context, accepting an enum or a string', function () {
    HoldSignup::factory()->prelaunch()->count(2)->create();
    HoldSignup::factory()->maintenance()->create();

    expect(HoldSignup::context(HoldSignupContext::Prelaunch)->count())->toBe(2)
        ->and(HoldSignup::context('maintenance')->count())->toBe(1);
});

it('soft-unsubscribes by stamping unsubscribed_at without deleting the row', function () {
    $signup = HoldSignup::factory()->create();

    $signup->unsubscribe();

    expect($signup->unsubscribed_at)->toBeInstanceOf(Carbon::class)
        ->and(HoldSignup::whereKey($signup->getKey())->exists())->toBeTrue()
        ->and(HoldSignup::subscribed()->count())->toBe(0);
});

it('keeps the original timestamp when unsubscribe is called twice', function () {
    $signup = HoldSignup::factory()->unsubscribed()->create();
    $original = $signup->unsubscribed_at;

    Carbon::setTestNow(Carbon::now()->addHour());
    $signup->unsubscribe();
    Carbon::setTestNow();

    expect($signup->unsubscribed_at->equalTo($original))->toBeTrue();
});
