<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use JamesGifford\Hold\Console\Commands\Concerns\ManagesHoldAssets;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Installer\PackageMigration;

/**
 * Removes everything the setup command published and (unless --keep-data) drops
 * the hold_signups table, returning the app to its pre-setup state.
 *
 * The only thing left after this runs is the Composer dependency itself: run
 * `composer remove jamesgifford/hold` to finish. That also removes the
 * maintenance-middleware container binding (it is a binding and nothing else),
 * so no host-app core file was ever touched.
 */
final class UninstallCommand extends Command
{
    use ManagesHoldAssets;

    protected $signature = 'jamesgifford:hold:uninstall
        {--force : Run unattended: skip confirmations and permit running in production}
        {--keep-data : Keep the hold_signups table and its migration file (skip the drop)}';

    protected $description = 'Remove everything Hold published and drop its table.';

    public function handle(HoldState $state, PackageMigration $migration): int
    {
        $this->info('JamesGifford Hold — uninstall');
        $this->newLine();

        $keepData = (bool) $this->option('keep-data');

        $this->describePlan($keepData);

        if (! $this->confirmUninstall()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->removeFile($this->configTarget(), 'config');
        // modelDirectory() is now the shared app/Models — remove only our file.
        $this->removeFile($this->modelTarget(), 'HoldSignup model');

        foreach ($this->viewMap() as $target) {
            $this->removeFile($target, $this->relative($target));
        }

        // Clean up a wrongly-named errors/503.php from an earlier buggy install.
        $legacy503 = $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'errors'.DIRECTORY_SEPARATOR.'503.php');
        if (is_file($legacy503)) {
            @unlink($legacy503);
            $this->line('  - removed legacy '.$this->relative($legacy503));
        }

        // Prune the mail/ subdirectory first, then the vendor/hold parent — an
        // empty child would otherwise keep the parent from pruning.
        $vendorHold = $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'hold');
        $this->pruneEmptyDirectory($vendorHold.DIRECTORY_SEPARATOR.'mail');
        $this->pruneEmptyDirectory($vendorHold);

        $this->removeStorage($state);

        $this->handleData($migration, $keepData);

        $this->newLine();
        $this->info('Uninstall complete.');
        $this->line('Finish by removing the dependency:  composer remove jamesgifford/hold');
        $this->line('(that also removes the maintenance-middleware container binding).');

        return self::SUCCESS;
    }

    protected function describePlan(bool $keepData): void
    {
        $this->warn('This will remove:');
        $this->line('  • config/jamesgifford/hold.php');
        $this->line('  • the published HoldSignup model');
        $this->line('  • the published views (prelaunch, maintenance, email templates, errors/503 shim)');
        $this->line('  • the storage/jamesgifford/hold/ directory');

        if ($keepData) {
            $this->newLine();
            $this->line('The hold_signups table and its migration file will be KEPT (--keep-data).');
        } else {
            $this->line('  • the published migration file');
            $this->newLine();
            $this->warn('It will also DROP the hold_signups table — permanently deleting captured signups.');
        }
        $this->newLine();
    }

    protected function confirmUninstall(): bool
    {
        $isProduction = $this->laravel->environment() === 'production';

        if ($isProduction && ! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Refusing to uninstall in production without --force.');

                return false;
            }
        }

        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        if (! $this->confirm('Proceed with the uninstall described above?', false)) {
            $this->warn('Uninstall aborted.');

            return false;
        }

        return true;
    }

    /**
     * Drop the table + remove the migration, unless --keep-data (or the user
     * declines the explicit drop confirmation), in which case both are left.
     */
    protected function handleData(PackageMigration $migration, bool $keepData): void
    {
        if ($keepData) {
            $this->line('  - kept the '.PackageMigration::TABLE.' table and migration file (--keep-data)');
            $this->line('    (dropping would delete captured signups; remove them yourself when ready)');

            return;
        }

        if (! $this->shouldDropTable()) {
            $this->line('  - kept the '.PackageMigration::TABLE.' table and migration file (drop declined)');

            return;
        }

        Schema::dropIfExists(PackageMigration::TABLE);
        $migration->forgetMigrationRecord();
        $this->line('  - dropped the '.PackageMigration::TABLE.' table');

        $removed = $migration->deletePublishedFiles();
        foreach ($removed as $name) {
            $this->line('  - removed published migration '.$name);
        }
    }

    protected function shouldDropTable(): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Drop the '.PackageMigration::TABLE.' table now? This permanently deletes all captured signups.', false);
    }

    protected function removeStorage(HoldState $state): void
    {
        $directory = $state->directory();

        if (! is_dir($directory)) {
            $this->line('  - storage/jamesgifford/hold/ not present');

            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($directory);
        $this->pruneEmptyDirectory(dirname($directory));
        $this->line('  - removed storage/jamesgifford/hold/');
    }

    protected function removeFile(string $path, string $label): void
    {
        if (! is_file($path)) {
            $this->line("  - {$label} not present");

            return;
        }

        @unlink($path);
        $this->line("  - removed {$label}");
    }

    protected function pruneEmptyDirectory(string $directory): void
    {
        if (is_dir($directory) && (glob($directory.DIRECTORY_SEPARATOR.'*') ?: []) === []) {
            @rmdir($directory);
        }
    }

    protected function relative(string $path): string
    {
        $base = $this->laravel->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
