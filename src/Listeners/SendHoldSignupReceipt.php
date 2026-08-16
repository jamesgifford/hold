<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Events\HoldSignupCaptured;
use JamesGifford\Hold\Hold;

/**
 * Sends the optional "you're on the list" receipt when a signup is captured or
 * re-armed and config notifications.send_signup_receipt is enabled. Fires only
 * for genuinely new or re-armed rows (HoldSignupCaptured is not dispatched for a
 * same-cycle duplicate), and never to an unsubscribed address — the unsubscribe
 * data contract excludes those from every package email.
 *
 * Superseded by email verification: when verification.required is on (the
 * default), SendSignupVerification is the signup-time email — its own
 * "you're on the list" confirmation is the successful verify click, not a
 * separate receipt — so this bails out regardless of send_signup_receipt.
 */
final class SendHoldSignupReceipt
{
    public function handle(HoldSignupCaptured $event): void
    {
        if (! config('jamesgifford.hold.notifications.send_signup_receipt', false)) {
            return;
        }

        if ((bool) config('jamesgifford.hold.verification.required', true)) {
            return;
        }

        // Respect the unsubscribe data contract: never email an unsubscribed row.
        if ($event->signup->unsubscribed_at !== null) {
            return;
        }

        $class = Hold::notificationClass('signup_receipt');
        Notification::route('mail', $event->signup->email)->notify(new $class($event->signup));
    }
}
