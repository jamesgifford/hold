<?php

declare(strict_types=1);

use JamesGifford\Hold\Hold;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Tests\Support\Fixtures\LegacyPublishedSignup;

/*
 * How `models.signup` is resolved.
 *
 * The package resolves an app-owned class it has no static relationship with —
 * the published model is a copy, not a subclass — so HoldSignupContract is the
 * only thing tying the two together. These cover what happens at each edge of
 * that resolution.
 */

it('resolves a class that implements the contract', function () {
    config()->set('jamesgifford.hold.models.signup', HoldSignup::class);

    expect(Hold::signupModel())->toBe(HoldSignup::class);
});

it('falls back to the package model when the configured class is not published yet', function () {
    // The shipped default points at App\Models\HoldSignup, which does not exist
    // until setup runs. That is the normal pre-install state, not an error.
    config()->set('jamesgifford.hold.models.signup', 'App\Models\HoldSignup');

    expect(Hold::signupModel())->toBe(HoldSignup::class);
});

it('refuses a configured model that predates the contract, rather than silently swapping it', function () {
    // A model published before 1.1.0 loads fine but does not implement the
    // contract. Falling back to the package model would throw away the app's own
    // customisations without a word, so this fails loudly instead.
    config()->set('jamesgifford.hold.models.signup', LegacyPublishedSignup::class);

    expect(fn () => Hold::signupModel())
        ->toThrow(RuntimeException::class, 'does not implement');
});

it('names the fix in the refusal message', function () {
    config()->set('jamesgifford.hold.models.signup', LegacyPublishedSignup::class);

    try {
        Hold::signupModel();
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain(LegacyPublishedSignup::class)
        ->toContain('HoldSignupContract')
        ->toContain('jamesgifford:hold:setup');
});

it('accepts a subclass of a contract-implementing model', function () {
    // The documented customisation path: subclass the published model.
    $subclass = new class extends HoldSignup {};
    config()->set('jamesgifford.hold.models.signup', $subclass::class);

    expect(Hold::signupModel())->toBe($subclass::class);
});
