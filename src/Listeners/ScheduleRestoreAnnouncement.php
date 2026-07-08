<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeDisabled;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Support\AnnouncementScheduler;

/**
 * When the app comes back up (`php artisan up`), schedules the delayed "we're
 * back" announcement to maintenance signups — but only if config
 * auto_announce_on_up is enabled. Otherwise the operator announces manually.
 */
final class ScheduleRestoreAnnouncement
{
    public function handle(MaintenanceModeDisabled $event): void
    {
        AnnouncementScheduler::scheduleIfAuto(SignupContext::Maintenance);
    }
}
