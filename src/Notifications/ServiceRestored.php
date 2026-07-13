<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Notifications\Concerns\FormatsHoldMail;

/**
 * "We're back" — sent to maintenance-context signups when the app comes back up.
 * Override via config notifications.classes.service_restored.
 *
 * Body renders through the package's self-contained hold::mail.announcement
 * template (the published copy wins when present); edit copy/links/colors there.
 */
class ServiceRestored extends Notification
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
            ->subject('We\'re back online')
            ->view('hold::mail.announcement', [
                'signup' => $this->signup,
                'context' => HoldSignupContext::Maintenance,
                'heading' => 'We\'re back!',
                'body' => 'Maintenance is complete and everything is up and running again.',
                'actionText' => 'Return to the site',
                'actionUrl' => url('/'),
                'footnote' => 'You\'re receiving this because you asked to be notified.',
            ]);

        return $this->applyFrom($mail);
    }
}
