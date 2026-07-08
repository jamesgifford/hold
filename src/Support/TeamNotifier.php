<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Hold;
use JamesGifford\Hold\SignupContext;

/**
 * Sends the "hold enabled" team notice, shared by the prelaunch enable command
 * and the maintenance-mode-enabled listener. A no-op when no team addresses are
 * configured, so callers never have to check.
 */
final class TeamNotifier
{
    public static function holdEnabled(SignupContext $context): bool
    {
        $addresses = Hold::teamAddresses();

        if ($addresses === []) {
            return false;
        }

        $class = Hold::notificationClass('team_hold_enabled');
        Notification::route('mail', $addresses)->notify(new $class($context));

        return true;
    }
}
