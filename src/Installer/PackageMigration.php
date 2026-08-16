<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Installer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;

/**
 * Publishes, locates, and tears down the package's migrations in a consuming
 * app.
 *
 * Each migration is PUBLISHED into the app's database/migrations as a real file
 * with a FRESH publish-time timestamp (never loadMigrationsFrom'd), so it runs
 * in the normal merged order alongside the app's own migrations. Because the
 * published filename's timestamp differs from the source, a published copy is
 * identified by its STEM (the descriptive part after the timestamp prefix),
 * which is what makes publishing idempotent and uninstall reliable.
 *
 * Idempotency is per-stem, not all-or-nothing: an app that already has the
 * create migration published (from before add_verification existed) gets only
 * the missing stem published on a later `setup` re-run — the ordinary path a
 * package upgrade takes.
 */
final class PackageMigration
{
    /**
     * Stable descriptive stems of the package's migrations, in the order they
     * must run — each stub's filename timestamp is offset by its position
     * here (see publish()), so a fresh install always applies them in this
     * order regardless of which stems were already published.
     *
     * @var list<string>
     */
    public const STEMS = ['create_hold_signups_table', 'add_verification_to_hold_signups_table'];

    public const TABLE = 'hold_signups';

    /**
     * Marker embedded in each published file so it stays recognisable as a
     * package migration even after being given a fresh timestamp filename.
     */
    private const MARKER = 'Published by the jamesgifford/hold package';

    public function __construct(private readonly Application $app) {}

    /**
     * A package migration stub's source path (source of truth for that
     * migration's schema change).
     */
    public function sourcePath(string $stem): string
    {
        return dirname(__DIR__, 2).'/database/migrations/'.$stem.'.php.stub';
    }

    /**
     * Full paths of the app's published copies of every package migration
     * (matched by stem, so fresh-timestamp filenames are recognised).
     *
     * @return list<string>
     */
    public function publishedFiles(): array
    {
        $dir = $this->app->databasePath('migrations');
        $found = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            if (in_array($this->stemFor(basename($path, '.php')), self::STEMS, true)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Whether every package migration has a published copy.
     */
    public function isPublished(): bool
    {
        return count($this->publishedFiles()) >= count(self::STEMS);
    }

    /**
     * Publish every not-yet-published migration stub with a fresh timestamp.
     * Each stem is independently idempotent — already-published stems are
     * left untouched, not republished or duplicated.
     *
     * @return list<string> filenames actually created (empty when nothing was missing)
     */
    public function publish(): array
    {
        $dir = $this->app->databasePath('migrations');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // Stamp today's date at midnight so the earliest stem sorts as early
        // as possible within the publish day — before any project migration
        // made the same day (make:migration always stamps a real
        // time-of-day) — with each later stem offset a second further so
        // they always sort after one another regardless of which were
        // already published.
        $today = Carbon::now()->startOfDay();
        $created = [];

        foreach (self::STEMS as $position => $stem) {
            if ($this->isStemPublished($stem)) {
                continue;
            }

            $filename = $today->copy()->addSeconds($position)->format('Y_m_d_His').'_'.$stem.'.php';
            $target = $dir.DIRECTORY_SEPARATOR.$filename;

            $contents = (string) file_get_contents($this->sourcePath($stem));
            file_put_contents($target, $this->annotate($contents));

            $created[] = $filename;
        }

        return $created;
    }

    /**
     * Delete the app's published copies of every package migration. Returns
     * the basenames removed.
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
     * Remove the migration repository row(s) for every package migration,
     * matched by stem, so a later re-setup + migrate re-runs them cleanly.
     * No-op when the repository does not exist.
     */
    public function forgetMigrationRecord(): void
    {
        $repository = $this->app->make('migrator')->getRepository();

        if (! $repository->repositoryExists()) {
            return;
        }

        foreach ($repository->getRan() as $ran) {
            if (in_array($this->stemFor($ran), self::STEMS, true)) {
                $repository->delete((object) ['migration' => $ran]);
            }
        }
    }

    /**
     * Whether this specific stem already has a published copy.
     */
    private function isStemPublished(string $stem): bool
    {
        foreach ($this->publishedFiles() as $path) {
            if ($this->stemFor(basename($path, '.php')) === $stem) {
                return true;
            }
        }

        return false;
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
