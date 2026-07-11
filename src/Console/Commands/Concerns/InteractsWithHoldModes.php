<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands\Concerns;

use JamesGifford\Hold\HoldState;

/**
 * Shared hold-mode detection and status reporting for the enable/disable
 * commands, which present the two modes (prelaunch and native maintenance) as
 * one unified interface under the rule that only one may be active at a time.
 */
trait InteractsWithHoldModes
{
    /**
     * The single active hold mode, or null when the app is live.
     *
     * Maintenance is checked first: Laravel's maintenance middleware runs before
     * the package's prelaunch middleware, so maintenance is what visitors
     * actually see if both are somehow active at once.
     */
    protected function activeHoldMode(): ?string
    {
        if ($this->laravel->isDownForMaintenance()) {
            return 'maintenance';
        }

        if ($this->laravel->make(HoldState::class)->isActive()) {
            return 'prelaunch';
        }

        return null;
    }

    /**
     * Print the resulting hold state so every run ends with an unambiguous line.
     */
    protected function printHoldStatus(): void
    {
        $this->newLine();
        $this->line('<info>Active hold:</info> '.($this->activeHoldMode() ?? 'none'));
    }
}
