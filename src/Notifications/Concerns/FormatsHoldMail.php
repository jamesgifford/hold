<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Shared mail formatting for the package notifications: the optional From
 * override. The package ships no user-facing unsubscribe link (unsubscribe is an
 * app-owned data contract — see the HoldSignup model + jamesgifford:hold:unsubscribe).
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
}
