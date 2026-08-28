<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands\Concerns;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\HoldState;

/**
 * Shared hold-mode detection and status reporting for the enable/disable/
 * preview/status commands, which present the two modes (prelaunch and native
 * maintenance) as one unified interface under the rule that only one may be
 * active at a time.
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
     * Same as activeHoldMode(), but names prelaunch's active SOURCE (env or
     * file) — the whole reason a source distinction exists: env-forced state
     * is invisible on the filesystem, so surfacing it here is what stops an
     * operator being confused by it.
     */
    protected function describeActiveHoldMode(): ?string
    {
        if ($this->laravel->isDownForMaintenance()) {
            return 'maintenance';
        }

        $state = $this->laravel->make(HoldState::class);

        if (! $state->isActive()) {
            return null;
        }

        return "prelaunch ({$state->source()})";
    }

    /**
     * Print the resulting hold state so every run ends with an unambiguous line.
     */
    protected function printHoldStatus(): void
    {
        $this->newLine();
        $this->line('<info>Active hold:</info> '.($this->describeActiveHoldMode() ?? 'none'));
    }

    /**
     * Print the signed preview link for the CURRENT prelaunch token, or a
     * fallback explanation when the package routes are not registered.
     * Shared by `enable prelaunch` (mints on first activation) and
     * `jamesgifford:hold:preview` (mints/re-mints on demand).
     */
    protected function printPreviewLink(HoldState $state): void
    {
        if (! Route::has('hold.preview')) {
            $this->newLine();
            $this->line('Package routes are not registered (routes.register = false), so no preview');
            $this->line('link can be generated. Wire the published routes stub to enable /preview.');

            return;
        }

        // Carry the current activation token so the link is revoked the moment
        // the hold is disabled (and re-enabling issues a fresh one).
        $this->newLine();
        $this->line('Preview the real app (sets a bypass cookie) with this signed link:');
        $this->newLine();
        $this->line('    '.URL::signedRoute('hold.preview', ['token' => $state->token()]));
    }
}
