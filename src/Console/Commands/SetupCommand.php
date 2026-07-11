<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Console\Commands\Concerns\ManagesHoldAssets;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Installer\PackageMigration;

/**
 * Installs the package into the host app: publishes config, the migration
 * (timestamped), the HoldSignup model, and the views; creates runtime storage; and
 * optionally migrates.
 *
 * Interactivity is gated. Run interactively it PAUSES right after publishing the
 * config so you can review/edit it, then RE-READS the (possibly edited) config
 * and honors it for every later step — publish locations, model namespace, etc.
 * --force runs unattended (skips the pause and every overwrite prompt, never
 * clobbering an existing file); --migrate runs the migration in that mode.
 *
 * Idempotent: existing assets are skipped or prompted, and the migration is
 * never published twice (it is matched by stem regardless of its timestamp).
 */
final class SetupCommand extends Command
{
    use ManagesHoldAssets;

    /** @var list<string> Full host-app paths of files this run published. */
    protected array $published = [];

    /** @var list<string> Descriptions of files/steps this run skipped. */
    protected array $skipped = [];

    protected $signature = 'jamesgifford:hold:setup
        {--force : Run unattended: skip the review pause and all overwrite prompts (never clobbering existing files), and permit running in production}
        {--migrate : Run the migration after publishing (use with --force for an unattended install)}';

    protected $description = 'Install Hold: publish config, migration, model, and views, then optionally migrate.';

    public function handle(HoldState $state, PackageMigration $migration): int
    {
        $this->info('JamesGifford Hold — setup');
        $this->newLine();

        if (! $this->passesProductionGuard()) {
            return self::FAILURE;
        }

        $interactive = $this->isInteractive();

        $this->step(1, 'Publishing config to config/jamesgifford/hold.php');
        $this->publishConfig($interactive);

        // The pause: review/edit the freshly published config before it drives
        // the rest of setup. Then re-read it so every later step honors edits.
        if ($interactive) {
            $this->reviewPause();
        }
        $this->reloadPublishedConfig();

        $this->step(2, 'Publishing the database migration');
        $this->publishMigration($migration);

        $this->step(3, 'Publishing the HoldSignup model');
        $this->publishModel($interactive);

        $this->step(4, 'Publishing views');
        $this->publishViews($interactive);

        $this->step(5, 'Creating runtime storage');
        $state->ensureDirectory();
        $this->line('  - storage/jamesgifford/hold/ ready (ignores its own contents)');
        $this->published[] = $state->directory().DIRECTORY_SEPARATOR.'.gitignore';

        $this->step(6, 'Database migration');
        $this->maybeMigrate($interactive);

        $this->printSummary();

        return self::SUCCESS;
    }

    protected function passesProductionGuard(): bool
    {
        if ($this->laravel->environment() !== 'production') {
            return true;
        }

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to run setup in production without --force.');

            return false;
        }

        if (! $this->confirm('This app appears to be in production. Run Hold setup here?', false)) {
            $this->warn('Setup aborted.');

            return false;
        }

        return true;
    }

    protected function isInteractive(): bool
    {
        return $this->input->isInteractive() && ! $this->option('force');
    }

    protected function publishConfig(bool $interactive): void
    {
        $this->publishContents($this->configTarget(), (string) file_get_contents($this->configSource()), 'config/jamesgifford/hold.php', $interactive);
    }

    protected function reviewPause(): void
    {
        $this->newLine();
        $this->line(str_repeat('─', 72));
        $this->line('  Review your configuration');
        $this->line(str_repeat('─', 72));
        $this->newLine();
        $this->line('Config was published to <info>config/jamesgifford/hold.php</info>. Review and adjust it');
        $this->line('now — route prefix, team addresses, announce delay, model location, prelaunch');
        $this->line('status code — then continue. Every step below re-reads and honors your edits.');
        $this->newLine();
        $this->ask('Press ENTER to continue');
    }

    /**
     * Re-read the published config file (plain require re-executes each call) so
     * later steps see any edits made during the pause.
     */
    protected function reloadPublishedConfig(): void
    {
        $target = $this->configTarget();

        if (is_file($target)) {
            $values = require $target;

            if (is_array($values)) {
                config()->set('jamesgifford.hold', $values);
            }
        }
    }

    protected function publishMigration(PackageMigration $migration): void
    {
        if ($migration->isPublished()) {
            $existing = array_map('basename', $migration->publishedFiles());
            $this->line('  - migration already published (skipped): '.implode(', ', $existing));
            $this->skipped[] = 'database migration (already published: '.implode(', ', $existing).')';

            return;
        }

        $filename = $migration->publish();
        $this->line('  - published migration '.$filename);
        $this->published[] = $this->laravel->databasePath('migrations').DIRECTORY_SEPARATOR.$filename;
    }

    protected function publishModel(bool $interactive): void
    {
        $this->publishContents($this->modelTarget(), $this->renderPublishedModel(), $this->relative($this->modelTarget()), $interactive);
    }

    protected function publishViews(bool $interactive): void
    {
        foreach ($this->viewMap() as $source => $target) {
            $this->publishContents($target, (string) file_get_contents($source), $this->relative($target), $interactive);
        }
    }

    protected function maybeMigrate(bool $interactive): void
    {
        $shouldMigrate = $this->option('migrate')
            || ($interactive && $this->confirm('Run the database migration now?', true));

        if (! $shouldMigrate) {
            $this->line('  - skipped — run `php artisan migrate` when you are ready');
            $this->skipped[] = 'database migration run (run `php artisan migrate` when ready)';

            return;
        }

        $code = $this->call('migrate', $this->option('force') ? ['--force' => true] : []);

        if ($code !== self::SUCCESS) {
            $this->warn('  - migration did not complete cleanly (see output above)');
            $this->skipped[] = 'database migration run (did not complete cleanly — see output above)';

            return;
        }

        $this->line('  - migration complete');
    }

    /**
     * Write $contents to $target, honoring existing files: prompt before
     * overwrite when interactive; skip silently when unattended (never clobber).
     */
    protected function publishContents(string $target, string $contents, string $label, bool $interactive): void
    {
        if (is_file($target)) {
            if (! $interactive) {
                $this->line("  - {$label} already present (left untouched)");
                $this->skipped[] = "{$label} (already present, left untouched)";

                return;
            }

            if (! $this->confirm("{$label} already exists. Overwrite it?", false)) {
                $this->line("  - {$label} kept (not overwritten)");
                $this->skipped[] = "{$label} (kept, not overwritten)";

                return;
            }
        }

        $directory = dirname($target);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($target, $contents);
        $this->line("  - published {$label}");
        $this->published[] = $target;
    }

    protected function printSummary(): void
    {
        $prefix = trim((string) config('jamesgifford.hold.routes.prefix', 'hold'), '/');

        $this->newLine();
        $this->info('Setup complete.');

        $this->newLine();
        if ($this->published === []) {
            $this->line('Published: nothing new (everything was already present).');
        } else {
            $this->line('Published these files:');
            foreach ($this->published as $path) {
                $this->line('  ✓ '.$path);
            }
        }

        if ($this->skipped !== []) {
            $this->newLine();
            $this->line('Skipped:');
            foreach ($this->skipped as $item) {
                $this->line('  – '.$item);
            }
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  • Configure team addresses and mail settings in config/jamesgifford/hold.php');
        $this->line('  • Start a hold:  php artisan jamesgifford:hold:enable prelaunch|maintenance');
        $this->line("  • Preview / signup routes live under /{$prefix}");
        $this->newLine();
        $this->warn('Maintenance mode: use plain `php artisan down` — never `down --render`, which');
        $this->line('  bypasses the HTTP kernel and would break the 503 page\'s signup form.');
    }

    protected function step(int $number, string $message): void
    {
        $this->newLine();
        $this->line("<info>→ Step {$number}/6:</info> {$message}");
    }

    protected function relative(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
