<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use JamesGifford\Hold\Announcements\ScheduleOutcome;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Jobs\SendAnnouncement;

/**
 * Dispatches the delayed announcement when config `auto_announce_on_up` is on.
 *
 * The delay is the change-of-mind window: the job re-checks state on run and
 * aborts if the hold is active again. Shared by the prelaunch disable command
 * and the maintenance-mode-disabled listener; a no-op when auto-announce is off.
 *
 * That window only exists if the queue can actually defer work. A sync
 * connection runs the job inline and throws the delay away, so this refuses to
 * dispatch there rather than firing an unrecallable mass email the instant a
 * hold ends.
 */
final class AnnouncementScheduler
{
    public static function scheduleIfAuto(HoldSignupContext $context): ScheduleOutcome
    {
        if (! self::autoAnnounceEnabled()) {
            return ScheduleOutcome::AutoAnnounceDisabled;
        }

        if (self::autoAnnounceIsUnusable()) {
            Log::warning('Hold: auto-announce skipped — the default queue connection discards delays, so the change-of-mind window would be zero. Nothing was sent; run `php artisan jamesgifford:hold:announce` when you are ready.', [
                'context' => $context->value,
                'connection' => (string) config('queue.default'),
                'delay_minutes' => self::delayMinutes(),
            ]);

            return ScheduleOutcome::QueueCannotDelay;
        }

        SendAnnouncement::dispatch($context)->delay(Carbon::now()->addMinutes(self::delayMinutes()));

        return ScheduleOutcome::Scheduled;
    }

    public static function autoAnnounceEnabled(): bool
    {
        return (bool) config('jamesgifford.hold.notifications.auto_announce_on_up', false);
    }

    /**
     * Auto-announce is switched on but cannot deliver what it promises here:
     * there is a delay configured, and the default queue connection discards
     * delays. One definition, so the scheduler and the commands that report to
     * the operator can never disagree about it.
     */
    public static function autoAnnounceIsUnusable(): bool
    {
        return self::autoAnnounceEnabled()
            && self::delayMinutes() > 0
            && ! self::queueCanDelay();
    }

    /**
     * Whether the app's default queue connection can actually defer a job.
     *
     * Illuminate\Queue\SyncQueue::later() forwards straight to push(), so a sync
     * connection silently runs a "delayed" job immediately.
     */
    public static function queueCanDelay(): bool
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    public static function delayMinutes(): int
    {
        return max(0, (int) config('jamesgifford.hold.notifications.announce_delay_minutes', 10));
    }
}
