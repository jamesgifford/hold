<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands\Concerns;

/**
 * Shared path resolution for the setup and uninstall commands.
 *
 * Every path is derived from the live config at call time, so both commands
 * honor a config that was edited during setup's review pause (route prefix,
 * model namespace/path, etc.) — there are no hardcoded defaults past the pause.
 */
trait ManagesHoldAssets
{
    protected function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    protected function configTarget(): string
    {
        return $this->laravel->configPath('jamesgifford'.DIRECTORY_SEPARATOR.'hold.php');
    }

    protected function configSource(): string
    {
        return $this->packageRoot().'/config/hold.php';
    }

    protected function modelNamespace(): string
    {
        return trim((string) config('jamesgifford.hold.models.namespace', 'App\\Models\\Hold'), '\\');
    }

    protected function modelDirectory(): string
    {
        $relative = trim((string) config('jamesgifford.hold.models.path', 'app/Models/Hold'), '/');

        return $this->laravel->basePath($relative);
    }

    protected function modelTarget(): string
    {
        return $this->modelDirectory().DIRECTORY_SEPARATOR.'Signup.php';
    }

    protected function modelSource(): string
    {
        return $this->packageRoot().'/src/Models/Signup.php';
    }

    /**
     * Published views: [source => target]. The two holding-page partials go to
     * the vendor views path; the 503 view goes to the app's errors directory so
     * Laravel renders it during native maintenance mode.
     *
     * @return array<string, string>
     */
    protected function viewMap(): array
    {
        $root = $this->packageRoot().'/resources/views';
        $vendor = $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'hold');

        return [
            $root.'/prelaunch.blade.php' => $vendor.DIRECTORY_SEPARATOR.'prelaunch.blade.php',
            $root.'/unsubscribed.blade.php' => $vendor.DIRECTORY_SEPARATOR.'unsubscribed.blade.php',
            $root.'/errors/503.blade.php' => $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'errors'.DIRECTORY_SEPARATOR.'503.blade.php'),
        ];
    }

    /**
     * The published Signup model, with its namespace rewritten to the configured
     * app namespace so the app owns a self-contained, correct file.
     */
    protected function renderPublishedModel(): string
    {
        $contents = (string) file_get_contents($this->modelSource());

        return preg_replace(
            '/^namespace\s+JamesGifford\\\\Hold\\\\Models;/m',
            'namespace '.$this->modelNamespace().';',
            $contents,
            1,
        ) ?? $contents;
    }
}
