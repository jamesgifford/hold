<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Support\AnnouncementScheduler;

/**
 * Deactivates prelaunch ("coming soon") mode.
 *
 * Idempotent. When config `notifications.auto_announce_on_up` is enabled, this
 * also schedules the launch announcement to prelaunch signups (wired in a later
 * phase) after the configured delay.
 */
final class DisableCommand extends Command
{
    protected $signature = 'jamesgifford:hold:disable';

    protected $description = 'Deactivate prelaunch ("coming soon") mode.';

    public function handle(HoldState $state): int
    {
        if (! $state->isActive()) {
            $this->info('Prelaunch mode is already inactive. Nothing to do.');

            return self::SUCCESS;
        }

        $state->disable();

        $this->info('Prelaunch mode disabled. The app is live again.');
        $this->afterDisable();

        return self::SUCCESS;
    }

    /**
     * When auto-announce is enabled, schedule the delayed launch announcement to
     * prelaunch signups and say so; otherwise point at the manual command.
     */
    protected function afterDisable(): void
    {
        if (AnnouncementScheduler::scheduleIfAuto(SignupContext::Prelaunch)) {
            $this->line(sprintf(
                'Launch announcement scheduled to prelaunch signups in %d minute(s). Re-enabling within that window cancels it.',
                AnnouncementScheduler::delayMinutes(),
            ));

            return;
        }

        $this->line('Run `php artisan jamesgifford:hold:announce` when you want to email your signups.');
    }
}
