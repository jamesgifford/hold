<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Support\TeamNotifier;

/**
 * Sends the team the "hold enabled" notice when the app enters Laravel's native
 * maintenance mode (`php artisan down`). No-op if no team addresses configured.
 */
final class SendTeamHoldNotice
{
    public function handle(MaintenanceModeEnabled $event): void
    {
        TeamNotifier::holdEnabled(SignupContext::Maintenance);
    }
}
