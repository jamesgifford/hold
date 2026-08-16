<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\SignupVerification;

/*
 * The verification email: mirrors EmailCopyTest.php / MailPaletteTest.php's
 * conventions for the other mail templates — palette/appearance resolution,
 * config-driven subject, and a published-copy override winning over the
 * package default. publishEditedMail() lives in tests/Pest.php.
 */

it('renders the default wording on a fresh install, carrying the passed verify URL', function () {
    $signup = HoldSignup::factory()->unverified()->create();
    $mail = (new SignupVerification($signup, 'https://example.test/hold/verify?signature=abc'))
        ->toMail(new AnonymousNotifiable);

    $body = holdRender($mail->view, $mail->viewData);

    // {{ }} escapes apostrophes, so match an apostrophe-free fragment. The
    // negatives prove this renders through the self-contained template, not
    // Laravel's markdown mail layout.
    expect($body)
        ->toContain('Confirm your email')
        ->toContain('<!-- hold:verify -->')
        ->toContain('https://example.test/hold/verify?signature=abc')
        ->not->toContain('content-cell')
        ->not->toContain('mail::message');
});

it('reads the subject from config, defaulting to the shipped string', function () {
    $signup = HoldSignup::factory()->unverified()->create();

    expect((new SignupVerification($signup, 'https://example.test/verify'))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('Confirm your email address');

    config()->set('jamesgifford.hold.notifications.subject_verify', 'Custom verify subject');

    expect((new SignupVerification($signup, 'https://example.test/verify'))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('Custom verify subject');
});

it('resolves through the palette/appearance tiers same as the other mail templates', function () {
    config()->set('jamesgifford.hold.appearance.mail.bg', '#111827');

    $signup = HoldSignup::factory()->unverified()->create();
    $mail = (new SignupVerification($signup, 'https://example.test/verify'))->toMail(new AnonymousNotifiable);

    expect(holdRender($mail->view, $mail->viewData))->toContain('background:#111827; color:#f5f6f8;');
});

it('lets a published template override win over the package default', function () {
    publishEditedMail('verify', ['Confirm your email address' => 'EDITED-VERIFY-COPY']);

    $signup = HoldSignup::factory()->unverified()->create();
    $mail = (new SignupVerification($signup, 'https://example.test/verify'))->toMail(new AnonymousNotifiable);

    expect(holdRender($mail->view, $mail->viewData))->toContain('EDITED-VERIFY-COPY');
});
