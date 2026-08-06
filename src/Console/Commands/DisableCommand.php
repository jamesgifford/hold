<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Announcements\ScheduleOutcome;
use JamesGifford\Hold\Console\Commands\Concerns\InteractsWithHoldModes;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Support\AnnouncementScheduler;

/**
 * Deactivates whatever hold is active — the unified counterpart to
 * `jamesgifford:hold:enable {mode}`. Takes no arguments: it disables maintenance
 * (`artisan up`) and/or prelaunch (clears the flag file) as needed. If nothing
 * is active it says so and exits cleanly.
 *
 * Auto-announce is unchanged: `up` fires MaintenanceModeDisabled (the package
 * schedules the "we're back" announcement via its listener), and clearing
 * prelaunch schedules the launch announcement here. If BOTH holds were somehow
 * active, the maintenance context wins — it's what visitors actually saw — so
 * the prelaunch announcement is suppressed to avoid a duplicate.
 */
final class DisableCommand extends Command
{
    use InteractsWithHoldModes;

    protected $signature = 'jamesgifford:hold:disable';

    protected $description = 'Deactivate whatever hold is active (maintenance and/or prelaunch).';

    public function handle(HoldState $state): int
    {
        $maintenance = $this->laravel->isDownForMaintenance();
        $prelaunch = $state->isActive();

        if (! $maintenance && ! $prelaunch) {
            $this->info('No hold is active. Nothing to do.');
            $this->printHoldStatus();

            return self::SUCCESS;
        }

        if ($maintenance && ! $this->disableMaintenance()) {
            return self::FAILURE;
        }

        if ($prelaunch) {
            // When maintenance was also active, its `up` already scheduled the
            // (preferred) maintenance announcement — don't also schedule prelaunch.
            $this->disablePrelaunch($state, suppressAnnounce: $maintenance);
        }

        if ($maintenance && $prelaunch) {
            $this->warn('Both holds were active — disabled both, announcing with the maintenance context.');
        }

        $this->printHoldStatus();

        return self::SUCCESS;
    }

    private function disableMaintenance(): bool
    {
        // `up` dispatches MaintenanceModeDisabled → the package schedules the
        // "we're back" announcement (when auto-announce is on).
        $code = $this->call('up');

        if ($code !== self::SUCCESS) {
            $this->error('Failed to bring the application back up (see output above).');

            return false;
        }

        $this->info('Maintenance mode disabled (Laravel `up`). The app is live again.');

        // The listener already ran and, on a queue that discards delays, chose
        // not to dispatch — a decision that would otherwise only reach the logs.
        if (AnnouncementScheduler::autoAnnounceIsUnusable()) {
            $this->reportQueueCannotDelay();
        }

        return true;
    }

    private function disablePrelaunch(HoldState $state, bool $suppressAnnounce): void
    {
        $state->disable();
        $this->info('Prelaunch mode disabled. The app is live again.');

        if ($suppressAnnounce) {
            return;
        }

        match (AnnouncementScheduler::scheduleIfAuto(HoldSignupContext::Prelaunch)) {
            ScheduleOutcome::Scheduled => $this->line(sprintf(
                'Launch announcement scheduled to prelaunch signups in %d minute(s). Re-enabling within that window cancels it.',
                AnnouncementScheduler::delayMinutes(),
            )),
            ScheduleOutcome::QueueCannotDelay => $this->reportQueueCannotDelay(),
            ScheduleOutcome::AutoAnnounceDisabled => $this->line('Run `php artisan jamesgifford:hold:announce` when you want to email your signups.'),
        };
    }

    /**
     * Auto-announce is configured but cannot honour its delay here. Say so
     * plainly rather than let the operator believe a change-of-mind window
     * exists — nothing has been dispatched.
     */
    private function reportQueueCannotDelay(): void
    {
        $this->warn(sprintf(
            'Auto-announce is on, but the "%s" queue connection cannot delay jobs — the %d-minute change-of-mind window would be zero.',
            (string) config('queue.default'),
            AnnouncementScheduler::delayMinutes(),
        ));
        $this->line('Nothing was sent. Run `php artisan jamesgifford:hold:announce` when you are ready.');
    }
}
