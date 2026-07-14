<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;

/**
 * Render a notification's mail view exactly as the mail channel would: the
 * notification sets ->view($name, $data), so re-rendering that pair yields the
 * HTML body the recipient receives.
 */
function renderMail(object $notification, object $signup): string
{
    $mail = $notification->toMail($signup);

    return view($mail->view, $mail->viewData)->render();
}

it('renders the launch announcement through the self-contained template', function () {
    $signup = HoldSignup::factory()->prelaunch()->create();

    $html = renderMail(new LaunchAnnouncement($signup), $signup);

    // Context-appropriate heading + our own template, not Laravel's mail layout.
    // ({{ }} HTML-escapes the apostrophe, so match an apostrophe-free fragment.)
    expect($html)
        ->toContain('launched!')
        ->toContain('<!-- hold:announcement -->')
        ->not->toContain('content-cell')   // hallmark of Laravel's markdown mail layout
        ->not->toContain('mail::message');
});

it('renders the restore announcement with the maintenance heading', function () {
    $signup = HoldSignup::factory()->maintenance()->create();

    $html = renderMail(new ServiceRestored($signup), $signup);

    expect($html)
        ->toContain('back!')
        ->toContain('<!-- hold:announcement -->')
        ->not->toContain('content-cell')
        ->not->toContain('mail::message');
});

it('uses the published copy when one is present (override wins over the package view)', function () {
    $signup = HoldSignup::factory()->prelaunch()->create();

    // Simulate a published, developer-edited copy at views/vendor/hold/mail and
    // give it precedence, mirroring real vendor-view resolution.
    $override = sys_get_temp_dir().'/hold-mail-'.uniqid();
    File::ensureDirectoryExists($override.'/mail');
    File::put($override.'/mail/announcement.blade.php', 'EDITED-PUBLISHED-COPY');

    View::prependNamespace('hold', $override);
    View::flushFinderCache();

    try {
        $html = renderMail(new LaunchAnnouncement($signup), $signup);

        // The edit shows through, and the package default no longer renders.
        expect($html)
            ->toContain('EDITED-PUBLISHED-COPY')
            ->not->toContain('<!-- hold:announcement -->');
    } finally {
        File::deleteDirectory($override);
    }
});
