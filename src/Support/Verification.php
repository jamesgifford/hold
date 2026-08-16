<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Contracts\HoldSignupContract;
use JamesGifford\Hold\Hold;

/**
 * Mints and sends the signup-verification link.
 *
 * The link is a temporary signed route (config
 * verification.link_lifetime_days, default 7 days) carrying the signup's id.
 * A fixed expiry costs nothing: an unverified signup can always re-submit the
 * form for a fresh one (see HoldSignupController).
 *
 * Mirrors EnableCommand::printPreviewLink()'s graceful-degradation pattern:
 * when the package routes aren't registered (routes.register => false and no
 * self-hosted routes wired), url() returns null and send() no-ops rather than
 * throwing — a config choice the app made deliberately.
 */
final class Verification
{
    /**
     * The signed, expiring verify URL for a signup, or null when the
     * `hold.verify` route isn't registered.
     */
    public static function url(HoldSignupContract $signup): ?string
    {
        if (! Route::has('hold.verify')) {
            return null;
        }

        $days = max(0, (int) config('jamesgifford.hold.verification.link_lifetime_days', 7));

        return URL::temporarySignedRoute('hold.verify', Carbon::now()->addDays($days), ['signup' => $signup->getKey()]);
    }

    /**
     * Send the verification email, when a link can be minted.
     */
    public static function send(HoldSignupContract $signup): void
    {
        $url = self::url($signup);

        if ($url === null) {
            return;
        }

        $class = Hold::notificationClass('signup_verification');

        Notification::route('mail', $signup->email)->notify(new $class($signup, $url));
    }
}
