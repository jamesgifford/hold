<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JamesGifford\Hold\Notifications\Concerns\FormatsHoldMail;

/**
 * "We've launched" — sent to prelaunch-context signups when the coming-soon
 * hold ends. Override via config notifications.classes.launch_announcement.
 */
class LaunchAnnouncement extends Notification
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
            ->subject('We\'re live!')
            ->greeting('We\'ve launched!')
            ->line('Thanks for your patience — the wait is over and we\'re now live.')
            ->action('Take a look', url('/'));

        return $this->withUnsubscribe($this->applyFrom($mail), $this->signup);
    }
}
