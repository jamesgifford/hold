<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use RuntimeException;

/**
 * Test double that fails the way a broken mail transport or a bad custom
 * notification would, so the announcer's per-recipient error path is exercised
 * rather than assumed.
 */
class ExplodingLaunchAnnouncement extends LaunchAnnouncement
{
    public function __construct(Model $signup)
    {
        parent::__construct($signup);

        throw new RuntimeException('Notification could not be built.');
    }
}
