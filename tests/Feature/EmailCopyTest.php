<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\Notifications\TeamHoldEnabled;

/*
 * The email wording now lives in each template's top-of-file $copy block, so
 * these tests render the real notifications and (for the override cases) apply
 * the one-line edit a developer would make to the published template.
 */

/** Render a notification's mail view exactly as the mail channel would. */
function renderMailBody(object $notification): string
{
    $mail = $notification->toMail(new AnonymousNotifiable);

    return holdRender($mail->view, $mail->viewData);
}

// publishEditedMail() lives in tests/Pest.php — shared with MailPaletteTest.php.

// --- fresh install: default wording ----------------------------------------

it('renders each email\'s default wording on a fresh install', function () {
    $launch = renderMailBody(new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()));
    $restore = renderMailBody(new ServiceRestored(HoldSignup::factory()->maintenance()->create()));
    $receipt = renderMailBody(new HoldSignupReceipt(HoldSignup::factory()->prelaunch()->create()));
    $teamPrelaunch = renderMailBody(new TeamHoldEnabled(HoldSignupContext::Prelaunch));
    $teamMaintenance = renderMailBody(new TeamHoldEnabled(HoldSignupContext::Maintenance));

    // {{ }} escapes apostrophes, so match apostrophe-free fragments of the copy.
    // The negatives prove these render through the self-contained templates, not
    // Laravel's markdown mail layout (no `content-cell` / `mail::message` markers).
    expect($launch)
        ->toContain('launched!')
        ->toContain('<!-- hold:announcement -->')
        ->not->toContain('content-cell')
        ->not->toContain('mail::message');
    expect($restore)
        ->toContain('back!')
        ->toContain('<!-- hold:announcement -->')
        ->not->toContain('content-cell')
        ->not->toContain('mail::message');
    expect($receipt)->toContain('on the list')->toContain('<!-- hold:receipt -->');
    // The double quotes in the prelaunch heading escape to &quot;, so match
    // quote-free fragments.
    expect($teamPrelaunch)->toContain('Prelaunch')->toContain('hold enabled')->toContain('<!-- hold:team -->');
    expect($teamMaintenance)->toContain('Maintenance hold enabled')->toContain('<!-- hold:team -->');
});

// --- subjects come from config ---------------------------------------------

it('reads the announcement subjects from config, defaulting to the current strings', function () {
    // Defaults.
    expect((new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('We\'re live!');
    expect((new ServiceRestored(HoldSignup::factory()->maintenance()->create()))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('We\'re back online');

    // Overridden.
    config()->set('jamesgifford.hold.notifications.subject_launch', 'Custom launch subject');
    config()->set('jamesgifford.hold.notifications.subject_restored', 'Custom restore subject');

    expect((new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('Custom launch subject');
    expect((new ServiceRestored(HoldSignup::factory()->maintenance()->create()))->toMail(new AnonymousNotifiable)->subject)
        ->toBe('Custom restore subject');
});

// --- edited $copy is context-scoped ----------------------------------------

it('renders edited announcement $copy for the matching context only', function () {
    // Edit only the prelaunch heading in the published announcement template.
    publishEditedMail('announcement', ['launched!' => 'EDITED-LAUNCH-COPY']);

    $launch = renderMailBody(new LaunchAnnouncement(HoldSignup::factory()->prelaunch()->create()));
    $restore = renderMailBody(new ServiceRestored(HoldSignup::factory()->maintenance()->create()));

    expect($launch)->toContain('EDITED-LAUNCH-COPY');
    expect($restore)
        ->not->toContain('EDITED-LAUNCH-COPY')   // the maintenance set is untouched
        ->toContain('back!');
});
