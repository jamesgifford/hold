<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JamesGifford\Hold\Models\Signup;
use JamesGifford\Hold\SignupContext;

it('casts context to the enum and timestamps to Carbon', function () {
    $signup = Signup::factory()->prelaunch()->create();

    expect($signup->refresh()->context)->toBe(SignupContext::Prelaunch)
        ->and($signup->created_at)->toBeInstanceOf(Carbon::class);
});

it('scopes to not-yet-notified signups', function () {
    Signup::factory()->create();
    Signup::factory()->notified()->create();

    expect(Signup::notNotified()->count())->toBe(1)
        ->and(Signup::count())->toBe(2);
});

it('scopes to still-subscribed signups', function () {
    Signup::factory()->create();
    Signup::factory()->unsubscribed()->create();

    expect(Signup::subscribed()->count())->toBe(1);
});

it('scopes by context, accepting an enum or a string', function () {
    Signup::factory()->prelaunch()->count(2)->create();
    Signup::factory()->maintenance()->create();

    expect(Signup::context(SignupContext::Prelaunch)->count())->toBe(2)
        ->and(Signup::context('maintenance')->count())->toBe(1);
});

it('soft-unsubscribes by stamping unsubscribed_at without deleting the row', function () {
    $signup = Signup::factory()->create();

    $signup->unsubscribe();

    expect($signup->unsubscribed_at)->toBeInstanceOf(Carbon::class)
        ->and(Signup::whereKey($signup->getKey())->exists())->toBeTrue()
        ->and(Signup::subscribed()->count())->toBe(0);
});

it('keeps the original timestamp when unsubscribe is called twice', function () {
    $signup = Signup::factory()->unsubscribed()->create();
    $original = $signup->unsubscribed_at;

    Carbon::setTestNow(Carbon::now()->addHour());
    $signup->unsubscribe();
    Carbon::setTestNow();

    expect($signup->unsubscribed_at->equalTo($original))->toBeTrue();
});
