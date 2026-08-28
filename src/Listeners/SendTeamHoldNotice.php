<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Log;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Support\TeamNotifier;

/**
 * Runs whenever the app enters Laravel's native maintenance mode (whether via
 * `jamesgifford:hold:enable maintenance` or a plain `php artisan down` from
 * deploy tooling). It does two things:
 *
 *  1. Self-heals the one-hold invariant — if prelaunch was active when
 *     maintenance came up natively, it disables prelaunch (logging a line), so a
 *     direct `artisan down` can never leave two holds active at once. This
 *     cannot actually happen when prelaunch is env-forced (HoldState::
 *     isForced()) — there is no env var to unset from here — so that case
 *     logs a WARNING that both modes are now active instead, rather than
 *     silently failing to heal or claiming a disable that didn't happen.
 *     Maintenance still takes precedence at request time either way (its
 *     middleware runs before PrelaunchMode).
 *  2. Sends the team the "hold enabled" notice (no-op if no addresses set).
 */
final class SendTeamHoldNotice
{
    public function __construct(private readonly HoldState $state) {}

    public function handle(MaintenanceModeEnabled $event): void
    {
        if ($this->state->isActive()) {
            $wasForced = $this->state->isForced();

            // Clears any stray flag file either way; harmless (and a no-op)
            // when there isn't one, and does not — cannot — touch the env var.
            $this->state->disable();

            if ($wasForced) {
                Log::warning('Hold: maintenance mode was enabled while prelaunch is active via the HOLD_PRELAUNCH environment variable. Both modes are now active — the env var cannot be unset from here, so prelaunch stays forced on. Maintenance takes precedence at request time (its middleware runs first), but unset HOLD_PRELAUNCH and redeploy to fully restore the one-hold invariant.');
            } else {
                Log::info('Hold: prelaunch mode auto-disabled because maintenance mode was enabled (only one hold may be active at a time).');
            }
        }

        TeamNotifier::holdEnabled(HoldSignupContext::Maintenance);
    }
}
