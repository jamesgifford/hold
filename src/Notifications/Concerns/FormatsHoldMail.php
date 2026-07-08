<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use JamesGifford\Hold\Hold;

/**
 * Shared mail formatting for the package notifications: the optional From
 * override and the signed one-click unsubscribe line every public email carries.
 */
trait FormatsHoldMail
{
    /**
     * Apply the configured From override, if any (falls back to app defaults).
     */
    protected function applyFrom(MailMessage $mail): MailMessage
    {
        $address = config('jamesgifford.hold.mail.from.address');
        $name = config('jamesgifford.hold.mail.from.name');

        if (is_string($address) && $address !== '') {
            $mail->from($address, is_string($name) && $name !== '' ? $name : null);
        }

        return $mail;
    }

    /**
     * Append the signed unsubscribe link and a List-Unsubscribe header when the
     * package routes are registered.
     */
    protected function withUnsubscribe(MailMessage $mail, Model $signup): MailMessage
    {
        $url = Hold::unsubscribeUrl($signup);

        if ($url === null) {
            return $mail;
        }

        $mail->line('You are receiving this because you asked to be notified. Unsubscribe anytime: '.$url)
            ->withSymfonyMessage(function ($message) use ($url): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            });

        return $mail;
    }
}
