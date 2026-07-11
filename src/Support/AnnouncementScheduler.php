<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

use Illuminate\Support\Carbon;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Jobs\SendAnnouncement;

/**
 * Dispatches the delayed announcement when config `auto_announce_on_up` is on.
 *
 * The delay is the change-of-mind window: the job re-checks state on run and
 * aborts if the hold is active again. Shared by the prelaunch disable command
 * and the maintenance-mode-disabled listener; a no-op when auto-announce is off.
 */
final class AnnouncementScheduler
{
    public static function scheduleIfAuto(HoldSignupContext $context): bool
    {
        if (! config('jamesgifford.hold.notifications.auto_announce_on_up', false)) {
            return false;
        }

        $minutes = max(0, (int) config('jamesgifford.hold.notifications.announce_delay_minutes', 10));

        SendAnnouncement::dispatch($context)->delay(Carbon::now()->addMinutes($minutes));

        return true;
    }

    public static function delayMinutes(): int
    {
        return max(0, (int) config('jamesgifford.hold.notifications.announce_delay_minutes', 10));
    }
}
