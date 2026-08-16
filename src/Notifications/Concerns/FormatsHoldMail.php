<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Notifications\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

/**
 * Shared mail formatting for the package's list notifications: the optional
 * From override, and the opt-out link + List-Unsubscribe headers every list
 * email (the two announcements and the receipt) carries.
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
     * Add the opt-out link (as $unsubscribeUrl in the view data) and the
     * RFC 8058 List-Unsubscribe / List-Unsubscribe-Post headers. Null-safe
     * when the `hold.unsubscribe` route isn't registered (routes.register
     * => false and no self-hosted routes wired) — the mail still sends,
     * just without the link or headers, matching Verification::url()'s
     * degradation for the same config choice.
     */
    protected function applyUnsubscribe(MailMessage $mail, Model $signup): MailMessage
    {
        if (! Route::has('hold.unsubscribe')) {
            return $mail;
        }

        $url = URL::signedRoute('hold.unsubscribe', ['signup' => $signup->getKey()]);

        $mail->viewData['unsubscribeUrl'] = $url;

        return $mail->withSymfonyMessage(function (Email $message) use ($url): void {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$url}>");
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
