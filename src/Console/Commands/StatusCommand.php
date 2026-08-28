<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Console\Commands\Concerns\InteractsWithHoldModes;
use JamesGifford\Hold\HoldState;

/**
 * Reports which hold (if any) is active, its source, and whether a bypass
 * token currently exists for it.
 *
 * Exists mainly for env-forced prelaunch: that state is invisible on the
 * filesystem (no flag file to `ls`), so it's easy to be confused by. Always
 * exits successfully — this is a read-only report, never an error condition.
 */
final class StatusCommand extends Command
{
    use InteractsWithHoldModes;

    protected $signature = 'jamesgifford:hold:status';

    protected $description = 'Report which hold is active, its source, and whether a bypass token exists.';

    public function handle(HoldState $state): int
    {
        $this->printHoldStatus();

        if ($state->isActive()) {
            $this->line('Bypass token: '.($state->token() !== null
                ? 'present'
                : 'MISSING — run `jamesgifford:hold:preview` to mint one'));
        }

        return self::SUCCESS;
    }
}
