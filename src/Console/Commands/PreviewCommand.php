<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Console\Commands\Concerns\InteractsWithHoldModes;
use JamesGifford\Hold\HoldState;

/**
 * Mints a fresh prelaunch bypass token and prints its signed preview link,
 * WITHOUT changing whether prelaunch is active.
 *
 * `enable prelaunch` already mints a token on first activation, but that
 * command is never run under env-forced mode (there is nothing to enable —
 * see HoldState::isForced()), so this is the only way to get a bypass link
 * there. It works the same way for a command-driven (flag file) hold too —
 * useful any time you want to invalidate an existing preview link/cookie
 * without a disable+enable round trip.
 */
final class PreviewCommand extends Command
{
    use InteractsWithHoldModes;

    protected $signature = 'jamesgifford:hold:preview';

    protected $description = 'Mint a fresh prelaunch bypass token and print its signed preview link.';

    public function handle(HoldState $state): int
    {
        if (! $state->isActive()) {
            $this->error('No prelaunch hold is currently active.');
            $this->line('Run `jamesgifford:hold:enable prelaunch`, or set JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED=true and redeploy.');

            return self::FAILURE;
        }

        $state->reissueToken();

        $this->info("Minted a fresh prelaunch bypass token (source: {$state->source()}).");
        $this->line('Previous preview links and bypass cookies no longer work.');

        $this->printPreviewLink($state);

        if ($state->source() === 'env' && ! $state->tokenStoreIsPersistent()) {
            $this->newLine();
            $this->warn("The resolved token store ('{$state->tokenStoreName()}') will not persist across a");
            $this->warn('deploy or process restart — the minted token (and this link) would be lost. Point');
            $this->warn('`prelaunch.token_store` at a durable store (e.g. \'database\', \'redis\').');
        }

        return self::SUCCESS;
    }
}
