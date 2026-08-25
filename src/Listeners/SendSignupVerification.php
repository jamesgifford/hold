<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use JamesGifford\Hold\Events\HoldSignupCaptured;
use JamesGifford\Hold\Support\Verification;

/**
 * Sends the verification email when config verification.required is on
 * (the default), for every new-or-re-armed signup that isn't already a
 * confirmed, subscribed address.
 *
 * Deliberately DOES email an already-verified row that is currently opted
 * out: the verify link is the package's one path back in
 * (VerifyController::__invoke() is the only place unsubscribed_at is ever
 * cleared), so re-signing up after opting out must still reach the mailbox
 * owner to complete that path.
 */
final class SendSignupVerification
{
    public function handle(HoldSignupCaptured $event): void
    {
        $signup = $event->signup;

        if ($signup->verified_at !== null && $signup->unsubscribed_at === null) {
            return;
        }

        // Verification being off only excuses a still-subscribed row: an
        // opted-out one still needs the link, since it is the only path back
        // in regardless of this setting.
        if ($signup->unsubscribed_at === null && ! config('jamesgifford.hold.verification.required', true)) {
            return;
        }

        Verification::send($signup);
    }
}
