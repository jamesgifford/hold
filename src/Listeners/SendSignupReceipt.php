<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Events\SignupCaptured;
use JamesGifford\Hold\Hold;

/**
 * Sends the optional "you're on the list" receipt when a new signup is captured
 * and config notifications.send_signup_receipt is enabled. Fires only for
 * genuinely new rows (SignupCaptured is not dispatched for duplicates).
 */
final class SendSignupReceipt
{
    public function handle(SignupCaptured $event): void
    {
        if (! config('jamesgifford.hold.notifications.send_signup_receipt', false)) {
            return;
        }

        $class = Hold::notificationClass('signup_receipt');
        Notification::route('mail', $event->signup->email)->notify(new $class($event->signup));
    }
}
