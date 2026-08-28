<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Console\Commands\Concerns\ManagesHoldAssets;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Installer\PackageMigration;
use JamesGifford\Hold\Support\ConfigKeys;
use Throwable;

/**
 * Installs the package into the host app: publishes config, the migration
 * (timestamped), the HoldSignup model, and the views; creates runtime storage; and
 * optionally migrates.
 *
 * Config is NEVER blindly overwritten on a re-run. An already-published config
 * is diffed against this version's shipped config BY KEY (a plain array
 * comparison, not text/AST parsing) and the result — keys this version adds,
 * keys it no longer reads — is reported without writing anything, so an app's
 * customizations (appearance, addresses, whatever) survive picking up new
 * config across an upgrade. Only when the published file can't be safely
 * evaluated at all (a syntax error, or it doesn't return a plain array) does
 * this fall back to asking whether to overwrite with the fresh template or
 * abort the whole setup run — and only interactively; unattended runs leave
 * an undiffable config untouched and report the problem instead of guessing.
 * The model and views still use the older, simpler prompt-or-skip behavior:
 * overwrite only with explicit confirmation (interactive), never touch an
 * existing file otherwise.
 *
 * Interactivity is otherwise gated the same way: run interactively it PAUSES
 * right after the config step — but only when the config was actually just
 * written (a fresh install, or an undiffable config the user chose to
 * overwrite) — so you can review/edit it, then RE-READS the (possibly
 * edited) config and honors it for every later step. --force runs unattended
 * (skips the pause and every overwrite prompt, never clobbering an existing
 * file); --migrate runs the migration in that mode.
 *
 * Idempotent: existing assets are skipped, diffed, or prompted as
 * appropriate, and the migration is never published twice (it is matched by
 * stem regardless of its timestamp).
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
        if (! $this->publishConfig($interactive)) {
            return self::FAILURE;
        }

        // The pause: review/edit the freshly published config before it drives
        // the rest of setup. Then re-read it so every later step honors edits.
        // Only when the config was actually just WRITTEN — an existing config
        // that was diffed and left untouched has nothing new to review.
        if ($interactive && in_array($this->configTarget(), $this->published, true)) {
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

    /**
     * Publish the config (fresh install), or non-destructively diff it
     * against an already-published one instead of overwriting it.
     *
     * @return bool false means "abort the whole setup run" — the user chose
     *              not to overwrite an undiffable config; true means continue.
     */
    protected function publishConfig(bool $interactive): bool
    {
        $target = $this->configTarget();

        if (! is_file($target)) {
            $this->writeShippedConfig($target);
            $this->line('  - published config/jamesgifford/hold.php');
            $this->published[] = $target;

            return true;
        }

        $diff = $this->diffPublishedConfig($target);

        if ($diff === null) {
            return $this->handleUndiffableConfig($target, $interactive);
        }

        $this->reportConfigDiff($diff);

        return true;
    }

    /**
     * Compare the published config against this version's shipped config BY
     * KEY (dot notation), not by text — a plain array_diff_key, immune to
     * comment/whitespace/formatting differences. Returns null when the
     * published file cannot be safely evaluated at all (a syntax error, or
     * it returns something other than a plain array), which the caller
     * treats as "can't diff this."
     *
     * @return array{missing: array<string, mixed>, orphaned: array<string, mixed>}|null
     */
    protected function diffPublishedConfig(string $target): ?array
    {
        $published = $this->safeRequireConfigArray($target);

        if ($published === null) {
            return null;
        }

        /** @var array<string, mixed> $shipped */
        $shipped = require $this->configSource();

        $shippedKeys = ConfigKeys::flatten($shipped);
        $publishedKeys = ConfigKeys::flatten($published);

        return [
            'missing' => array_diff_key($shippedKeys, $publishedKeys),
            'orphaned' => array_diff_key($publishedKeys, $shippedKeys),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function safeRequireConfigArray(string $target): ?array
    {
        if (! is_file($target)) {
            return null;
        }

        try {
            $value = require $target;
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Report the diff without writing anything — new keys this version
     * adds, and keys the published config has that this version no longer
     * reads (most likely renamed or removed — harmless to leave, since
     * mergeConfigFrom only ever adds missing keys, never strips extras).
     *
     * @param  array{missing: array<string, mixed>, orphaned: array<string, mixed>}  $diff
     */
    protected function reportConfigDiff(array $diff): void
    {
        ['missing' => $missing, 'orphaned' => $orphaned] = $diff;

        if ($missing === [] && $orphaned === []) {
            $this->line('  - config/jamesgifford/hold.php already has every key this version ships (left untouched)');
            $this->skipped[] = 'config/jamesgifford/hold.php (already up to date, left untouched)';

            return;
        }

        $this->line('  - config/jamesgifford/hold.php left untouched — comparing it against this version:');
        $this->skipped[] = 'config/jamesgifford/hold.php (left untouched — see the diff above)';

        if ($missing !== []) {
            $this->newLine();
            $this->line('    New keys this version adds (add whichever you want — see CHANGELOG.md):');
            foreach ($missing as $key => $value) {
                $this->line("      {$key} => {$this->exportForDisplay($value)}");
            }
        }

        if ($orphaned !== []) {
            $this->newLine();
            $this->line('    Keys in your published config this version no longer reads (harmless to keep):');
            foreach ($orphaned as $key => $value) {
                $this->line("      {$key} => {$this->exportForDisplay($value)}");
            }
        }
    }

    protected function exportForDisplay(mixed $value): string
    {
        return str_replace("\n", ' ', var_export($value, true));
    }

    /**
     * The published config could not be safely compared — ask what to do
     * (interactive), or leave it untouched and say so (unattended, where
     * there is no one to ask).
     *
     * @return bool false means "abort the whole setup run".
     */
    protected function handleUndiffableConfig(string $target, bool $interactive): bool
    {
        $this->warn('  - could not safely compare your published config against this version');
        $this->line('    (loading it did not return a plain array — a syntax error, most likely)');

        if (! $interactive) {
            $this->line('  - left config/jamesgifford/hold.php untouched — run interactively to choose what to do');
            $this->skipped[] = 'config/jamesgifford/hold.php (left untouched — could not safely compare it)';

            return true;
        }

        $overwrite = 'Overwrite with the fresh package template (loses your customizations)';
        $abort = 'Abort setup (keep my file exactly as it is)';

        $choice = $this->choice('What would you like to do?', [$abort, $overwrite], 0);

        if ($choice === $overwrite) {
            $this->writeShippedConfig($target);
            $this->line('  - overwrote config/jamesgifford/hold.php with the fresh template');
            $this->published[] = $target;

            return true;
        }

        $this->error('Setup aborted — config/jamesgifford/hold.php was left untouched.');

        return false;
    }

    protected function writeShippedConfig(string $target): void
    {
        $directory = dirname($target);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        file_put_contents($target, (string) file_get_contents($this->configSource()));
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
     * later steps see any edits made during the pause. Safely a no-op — runtime
     * config is left as whatever it already was — when the file is missing or
     * cannot be evaluated (the undiffable-and-left-untouched case).
     */
    protected function reloadPublishedConfig(): void
    {
        $values = $this->safeRequireConfigArray($this->configTarget());

        if ($values !== null) {
            config()->set('jamesgifford.hold', $values);
        }
    }

    protected function publishMigration(PackageMigration $migration): void
    {
        $newlyPublished = $migration->publish();

        foreach ($newlyPublished as $filename) {
            $this->line('  - published migration '.$filename);
            $this->published[] = $this->laravel->databasePath('migrations').DIRECTORY_SEPARATOR.$filename;
        }

        $alreadyThere = array_diff(array_map('basename', $migration->publishedFiles()), $newlyPublished);

        if ($alreadyThere !== []) {
            $this->line('  - migration(s) already published (skipped): '.implode(', ', $alreadyThere));
            $this->skipped[] = 'database migration (already published: '.implode(', ', $alreadyThere).')';
        }
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
