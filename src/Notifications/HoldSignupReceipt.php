<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JamesGifford\Hold\Notifications\Concerns\FormatsHoldMail;

/**
 * Optional "you're on the list" receipt, sent on capture when config
 * notifications.send_signup_receipt is true. Override via
 * config notifications.classes.signup_receipt.
 */
class HoldSignupReceipt extends Notification
{
    use FormatsHoldMail;
    use Queueable;

    public function __construct(public readonly Model $signup) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You\'re on the list')
            ->line('Thanks — we\'ve added you to the list and will email you once when there\'s news.');

        return $this->applyFrom($mail);
    }
}
