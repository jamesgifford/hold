<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Installer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;

/**
 * Publishes, locates, and tears down the package's single migration in a
 * consuming app.
 *
 * The migration is PUBLISHED into the app's database/migrations as a real file
 * with a FRESH publish-time timestamp (never loadMigrationsFrom'd), so it runs
 * in the normal merged order alongside the app's own migrations. Because the
 * published filename's timestamp differs from the source, the published copy is
 * identified by its STEM (the descriptive part after the timestamp prefix),
 * which is what makes publishing idempotent and uninstall reliable.
 */
final class PackageMigration
{
    /**
     * Stable descriptive stem of the package migration.
     */
    public const STEM = 'create_hold_signups_table';

    public const TABLE = 'hold_signups';

    /**
     * Marker embedded in the published file so it stays recognisable as a
     * package migration even after being given a fresh timestamp filename.
     */
    private const MARKER = 'Published by the jamesgifford/hold package';

    public function __construct(private readonly Application $app) {}

    /**
     * The package's migration stub (source of truth for the table shape).
     */
    public function sourcePath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/create_hold_signups_table.php.stub';
    }

    /**
     * Full paths of the app's published copies of this migration (matched by
     * stem, so fresh-timestamp filenames are recognised).
     *
     * @return list<string>
     */
    public function publishedFiles(): array
    {
        $dir = $this->app->databasePath('migrations');
        $found = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            if ($this->stemFor(basename($path, '.php')) === self::STEM) {
                $found[] = $path;
            }
        }

        return $found;
    }

    public function isPublished(): bool
    {
        return $this->publishedFiles() !== [];
    }

    /**
     * Publish the migration with a fresh timestamp. Idempotent: skipped if a
     * copy is already published (never duplicated). Returns the created filename,
     * or null when skipped.
     */
    public function publish(): ?string
    {
        if ($this->isPublished()) {
            return null;
        }

        $dir = $this->app->databasePath('migrations');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Stamp today's date at midnight so the migration sorts as early as
        // possible within the publish day — before any project migration made
        // the same day (make:migration always stamps a real time-of-day).
        $timestamp = Carbon::now()->startOfDay()->format('Y_m_d_His');
        $filename = $timestamp.'_'.self::STEM.'.php';
        $target = $dir.DIRECTORY_SEPARATOR.$filename;

        $contents = (string) file_get_contents($this->sourcePath());
        file_put_contents($target, $this->annotate($contents));

        return $filename;
    }

    /**
     * Delete the app's published copies. Returns the basenames removed.
     *
     * @return list<string>
     */
    public function deletePublishedFiles(): array
    {
        $removed = [];

        foreach ($this->publishedFiles() as $path) {
            @unlink($path);
            $removed[] = basename($path);
        }

        return $removed;
    }

    /**
     * Remove the migration repository row(s) for this migration, matched by
     * stem, so a later re-setup + migrate re-runs it cleanly. No-op when the
     * repository does not exist.
     */
    public function forgetMigrationRecord(): void
    {
        $repository = $this->app->make('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            return;
        }

        foreach ($repository->getRan() as $ran) {
            if ($this->stemFor($ran) === self::STEM) {
                $repository->delete((object) ['migration' => $ran]);
            }
        }
    }

    private function stemFor(string $basename): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename) ?? $basename;
    }

    /**
     * Insert the "published by this package" marker after the declare line.
     * Idempotent.
     */
    private function annotate(string $contents): string
    {
        if (str_contains($contents, self::MARKER)) {
            return $contents;
        }

        $comment = "\n/*\n"
            .' * '.self::MARKER." (jamesgifford:hold:setup).\n"
            ." * The hold_signups table is owned by your app — modify with a new migration.\n"
            ." */\n";

        if (preg_match('/^declare\(strict_types=1\);$/m', $contents) === 1) {
            return preg_replace('/^(declare\(strict_types=1\);)$/m', '$1'.$comment, $contents, 1) ?? $contents;
        }

        return preg_replace('/^(<\?php)$/m', '$1'.$comment, $contents, 1) ?? $contents;
    }
}
