<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\SignupContext;
use JamesGifford\Hold\Support\TeamNotifier;

/**
 * Activates prelaunch ("coming soon") mode.
 *
 * Idempotent, and guarded in production: enabling a hold takes the whole app
 * offline for visitors, so in production it requires confirmation (or --force
 * when running unattended). On success it prints a ready-to-use signed preview
 * link so you can view the real app behind the holding page.
 */
final class EnableCommand extends Command
{
    protected $signature = 'jamesgifford:hold:enable
        {--force : Skip the production confirmation and run unattended}';

    protected $description = 'Activate prelaunch ("coming soon") mode.';

    public function handle(HoldState $state): int
    {
        if ($state->isActive()) {
            $this->info('Prelaunch mode is already active. Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->passesProductionGuard()) {
            return self::FAILURE;
        }

        $state->enable();

        $this->info('Prelaunch mode enabled. Visitors now see the "coming soon" holding page.');
        $this->afterEnable($state);
        $this->printPreviewLink($state);

        return self::SUCCESS;
    }

    /**
     * Notify the team that a prelaunch hold has begun (no-op if none configured).
     */
    protected function afterEnable(HoldState $state): void
    {
        if (TeamNotifier::holdEnabled(SignupContext::Prelaunch)) {
            $this->line('Sent the "hold enabled" notice to your team addresses.');
        }
    }

    /**
     * In production, enabling requires an explicit confirmation (interactive) or
     * --force (unattended) — mirrors the package's other guarded commands.
     */
    protected function passesProductionGuard(): bool
    {
        if ($this->laravel->environment() !== 'production' || $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->newLine();
            $this->error('Refusing to enable prelaunch mode in production without confirmation.');
            $this->line('Re-run with --force to take the app offline for visitors unattended:');
            $this->newLine();
            $this->line('    php artisan jamesgifford:hold:enable --force');

            return false;
        }

        return $this->confirm('This app appears to be in production. Enable prelaunch mode and show every visitor the holding page?', false);
    }

    private function printPreviewLink(HoldState $state): void
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
