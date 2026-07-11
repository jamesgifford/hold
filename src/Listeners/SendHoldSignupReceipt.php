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
 */
final class SendHoldSignupReceipt
{
    public function handle(HoldSignupCaptured $event): void
    {
        if (! config('jamesgifford.hold.notifications.send_signup_receipt', false)) {
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
