<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JamesGifford\Hold\Notifications\Concerns\FormatsHoldMail;

/**
 * "Confirm your email address" — sent when config verification.required is
 * true, so a signup must click the link before the announcer will ever email
 * it. Override via config notifications.classes.signup_verification.
 *
 * Body renders through the package's self-contained hold::mail.verify
 * template (the published copy wins when present); the wording lives in that
 * template's top-of-file $copy block. The subject is config-driven
 * (notifications.subject_verify). The verify URL is minted by
 * JamesGifford\Hold\Support\Verification and passed in rather than computed
 * here, so this class stays testable with an arbitrary URL string.
 */
class SignupVerification extends Notification
{
    use FormatsHoldMail;
    use Queueable;

    public function __construct(
        public readonly Model $signup,
        public readonly string $verifyUrl,
    ) {}

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
            ->subject((string) config('jamesgifford.hold.notifications.subject_verify', 'Confirm your email address'))
            ->view('hold::mail.verify', [
                'signup' => $this->signup,
                'verifyUrl' => $this->verifyUrl,
            ]);

        return $this->applyFrom($mail);
    }
}
