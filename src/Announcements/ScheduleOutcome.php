<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Announcements;

/**
 * What an auto-announce scheduling attempt actually did.
 *
 * The scheduler is called from two places that report very differently — the
 * disable command (console output) and the maintenance-mode listener (logs
 * only) — so it returns the outcome rather than a bare bool, and neither caller
 * has to re-derive the decision for itself.
 */
enum ScheduleOutcome: string
{
    /** The delayed announcement job was dispatched. */
    case Scheduled = 'scheduled';

    /** Config `auto_announce_on_up` is off; announcing is manual. */
    case AutoAnnounceDisabled = 'auto_announce_disabled';

    /**
     * Auto-announce is on, but the default queue connection discards delays, so
     * dispatching would send immediately with no change-of-mind window.
     * Nothing was dispatched.
     */
    case QueueCannotDelay = 'queue_cannot_delay';
}
