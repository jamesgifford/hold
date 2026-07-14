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
 * "We've launched" — sent to prelaunch-context signups when the coming-soon
 * hold ends. Override via config notifications.classes.launch_announcement.
 *
 * Body renders through the package's self-contained hold::mail.announcement
 * template (the published copy wins when present); the wording lives in that
 * template's top-of-file $copy['prelaunch'] set. The subject is config-driven
 * (notifications.subject_launch).
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
            ->subject((string) config('jamesgifford.hold.notifications.subject_launch', 'We\'re live!'))
            ->view('hold::mail.announcement', [
                'signup' => $this->signup,
                'context' => HoldSignupContext::Prelaunch,
            ]);

        return $this->applyFrom($mail);
    }
}
