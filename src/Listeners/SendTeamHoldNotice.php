<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Log;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Support\TeamNotifier;

/**
 * Runs whenever the app enters Laravel's native maintenance mode (whether via
 * `jamesgifford:hold:enable maintenance` or a plain `php artisan down` from
 * deploy tooling). It does two things:
 *
 *  1. Self-heals the one-hold invariant — if prelaunch was active when
 *     maintenance came up natively, it disables prelaunch (logging a line), so a
 *     direct `artisan down` can never leave two holds active at once.
 *  2. Sends the team the "hold enabled" notice (no-op if no addresses set).
 */
final class SendTeamHoldNotice
{
    public function __construct(private readonly HoldState $state) {}

    public function handle(MaintenanceModeEnabled $event): void
    {
        if ($this->state->isActive()) {
            $this->state->disable();
            Log::info('Hold: prelaunch mode auto-disabled because maintenance mode was enabled (only one hold may be active at a time).');
        }

        TeamNotifier::holdEnabled(SignupContext::Maintenance);
    }
}
