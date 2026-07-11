<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Support\Str;

/**
 * Manages the prelaunch ("coming soon") flag file.
 *
 * Prelaunch mode is package-owned state: its single source of truth is a flag
 * file under storage/jamesgifford/hold/. This keeps it independent of Laravel's
 * native maintenance mode (which the package leaves untouched) and survives
 * config caching.
 *
 * The flag file's CONTENTS are a per-activation bypass token: a fresh random
 * token is written each time the hold is enabled, and disabling deletes the
 * file. That makes every preview link and bypass cookie valid only for the
 * activation it was issued under — disabling (then re-enabling) revokes them all.
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
     * The current per-activation bypass token (the flag file's contents), or
     * null when the hold is inactive OR the flag file carries no token — a
     * legacy/empty file is treated as active but tokenless, so every bypass
     * comparison fails until the hold is disabled and re-enabled.
     */
    public function token(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        $contents = trim((string) @file_get_contents($this->flagPath()));

        return $contents === '' ? null : $contents;
    }

    /**
     * Activate prelaunch mode. Creates the storage directory (with a
     * .gitignore) if it does not exist yet. Idempotent — an already-active hold
     * keeps its existing token.
     */
    public function enable(): void
    {
        $this->ensureDirectory();

        if (! $this->isActive()) {
            // A fresh per-activation token becomes the flag file's contents, so
            // re-enabling always mints a new token and invalidates old bypasses.
            file_put_contents($this->flagPath(), Str::random(40).PHP_EOL);
        }
    }

    /**
     * Deactivate prelaunch mode. Idempotent. Deleting the file drops the token,
     * revoking every outstanding preview link and bypass cookie.
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
