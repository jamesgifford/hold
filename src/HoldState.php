<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

/**
 * Manages the prelaunch ("coming soon") flag file.
 *
 * Prelaunch mode is package-owned state: its single source of truth is a flag
 * file under storage/jamesgifford/hold/. This keeps it independent of Laravel's
 * native maintenance mode (which the package leaves untouched) and survives
 * config caching.
 *
 * The class fails gracefully: if the storage directory does not exist yet
 * (setup hasn't run), the mode simply reads as inactive rather than erroring.
 */
final class HoldState
{
    /**
     * Absolute path to the package's runtime storage directory.
     */
    public function directory(): string
    {
        return storage_path('jamesgifford/hold');
    }

    /**
     * Absolute path to the prelaunch flag file.
     */
    public function flagPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'prelaunch.active';
    }

    /**
     * Whether prelaunch mode is currently active.
     */
    public function isActive(): bool
    {
        return is_file($this->flagPath());
    }

    /**
     * Activate prelaunch mode. Creates the storage directory (with a
     * .gitignore) if it does not exist yet. Idempotent.
     */
    public function enable(): void
    {
        $this->ensureDirectory();

        if (! $this->isActive()) {
            file_put_contents($this->flagPath(), "Prelaunch mode is active.\n");
        }
    }

    /**
     * Deactivate prelaunch mode. Idempotent.
     */
    public function disable(): void
    {
        if ($this->isActive()) {
            @unlink($this->flagPath());
        }
    }

    /**
     * Ensure the runtime storage directory exists and ignores its own
     * contents from version control.
     */
    public function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $gitignore = $directory.DIRECTORY_SEPARATOR.'.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }
}
