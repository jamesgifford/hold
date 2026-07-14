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
 *
 * Body renders through the package's self-contained hold::mail.team template
 * (the published copy wins when present) — no Laravel mail layout. All wording
 * lives in that template's top-of-file $copy block.
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
            ->view('hold::mail.team', [
                'context' => $this->context,
            ]);

        return $this->applyFrom($mail);
    }
}
