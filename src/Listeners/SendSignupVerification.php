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
        if (! config('jamesgifford.hold.verification.required', true)) {
            return;
        }

        $signup = $event->signup;

        if ($signup->verified_at !== null && $signup->unsubscribed_at === null) {
            return;
        }

        Verification::send($signup);
    }
}
