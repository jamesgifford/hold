<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Announcements;

/**
 * Outcome of an announcement run: how many signups were emailed and how many
 * failed. Also carries `skipped` when the run aborted because the hold was
 * still (or again) active.
 */
final class AnnouncementResult
{
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $failed = 0,
        public readonly bool $skipped = false,
    ) {}

    public static function skipped(): self
    {
        return new self(skipped: true);
    }
}
