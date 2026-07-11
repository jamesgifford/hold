<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Notifications\Concerns\FormatsHoldMail;

/**
 * Internal heads-up to the team that a hold has begun. Sent to the configured
 * team addresses via an on-demand mail route (no User model needed). Override
 * by pointing config notifications.classes.team_hold_enabled at a subclass.
 */
class TeamHoldEnabled extends Notification
{
    use FormatsHoldMail;
    use Queueable;

    public function __construct(public readonly HoldSignupContext $context) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mode = $this->context === HoldSignupContext::Prelaunch
            ? 'Prelaunch ("coming soon")'
            : 'Maintenance';

        $mail = (new MailMessage)
            ->subject($mode.' hold enabled')
            ->line($mode.' mode is now active for your application.')
            ->line('Visitors are seeing the holding page and can leave their email to be notified.')
            ->line('Run `php artisan jamesgifford:hold:announce` (or disable the hold with auto-announce enabled) when you are ready to email them.');

        return $this->applyFrom($mail);
    }
}
