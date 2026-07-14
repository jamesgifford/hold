<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;

/*
 * The FormatsHoldMail trait applies the optional mail.from override to every
 * package notification. Documented config, so it earns a test.
 */

it('applies the configured mail.from override to package mail', function () {
    config()->set('jamesgifford.hold.mail.from.address', 'no-reply@acme.test');
    config()->set('jamesgifford.hold.mail.from.name', 'Acme');

    $mail = (new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()))
        ->toMail(new AnonymousNotifiable);

    expect($mail->from)->toBe(['no-reply@acme.test', 'Acme']);
});

it('leaves the from at app defaults when no override is configured', function () {
    // Defaults: mail.from.address / .name are null.
    $mail = (new HoldSignupReceipt(HoldSignup::factory()->create()))
        ->toMail(new AnonymousNotifiable);

    expect($mail->from)->toBe([]);
});

it('applies the from address without a name when only the address is set', function () {
    config()->set('jamesgifford.hold.mail.from.address', 'no-reply@acme.test');

    $mail = (new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()))
        ->toMail(new AnonymousNotifiable);

    expect($mail->from)->toBe(['no-reply@acme.test', null]);
});
