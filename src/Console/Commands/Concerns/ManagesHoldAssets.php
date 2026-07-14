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
        return trim((string) config('jamesgifford.hold.models.namespace', 'App\\Models'), '\\');
    }

    /**
     * The published model's class name (basename of the configured FQCN, e.g.
     * HoldSignup) — also the published filename.
     */
    protected function modelClassName(): string
    {
        $fqcn = trim((string) config('jamesgifford.hold.models.signup', 'App\\Models\\HoldSignup'), '\\');
        $basename = class_basename($fqcn);

        return $basename !== '' ? $basename : 'HoldSignup';
    }

    protected function modelDirectory(): string
    {
        $relative = trim((string) config('jamesgifford.hold.models.path', 'app/Models'), '/');

        return $this->laravel->basePath($relative);
    }

    protected function modelTarget(): string
    {
        return $this->modelDirectory().DIRECTORY_SEPARATOR.$this->modelClassName().'.php';
    }

    protected function modelSource(): string
    {
        return $this->packageRoot().'/src/Models/HoldSignup.php';
    }

    /**
     * Published views: [source => target]. The holding-page templates (prelaunch,
     * maintenance) and the email templates (announcement, team, receipt) go to
     * the vendor views path — edit them there. The 503 view is a thin shim that
     * goes to the app's errors directory so Laravel renders it during native
     * maintenance mode; it just includes the maintenance template.
     *
     * @return array<string, string>
     */
    protected function viewMap(): array
    {
        $root = $this->packageRoot().'/resources/views';
        $vendor = $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'hold');
        $mail = $vendor.DIRECTORY_SEPARATOR.'mail'.DIRECTORY_SEPARATOR;

        return [
            $root.'/prelaunch.blade.php' => $vendor.DIRECTORY_SEPARATOR.'prelaunch.blade.php',
            $root.'/maintenance.blade.php' => $vendor.DIRECTORY_SEPARATOR.'maintenance.blade.php',
            $root.'/mail/announcement.blade.php' => $mail.'announcement.blade.php',
            $root.'/mail/team.blade.php' => $mail.'team.blade.php',
            $root.'/mail/receipt.blade.php' => $mail.'receipt.blade.php',
            $root.'/errors/503.blade.php' => $this->laravel->resourcePath('views'.DIRECTORY_SEPARATOR.'errors'.DIRECTORY_SEPARATOR.'503.blade.php'),
        ];
    }

    /**
     * The published model, with its namespace rewritten to the configured app
     * namespace and its class renamed to the configured basename (HoldSignup) so
     * the app owns a self-contained, correct file at app/Models/HoldSignup.php.
     */
    protected function renderPublishedModel(): string
    {
        $contents = (string) file_get_contents($this->modelSource());

        $contents = preg_replace(
            '/^namespace\s+JamesGifford\\\\Hold\\\\Models;/m',
            'namespace '.$this->modelNamespace().';',
            $contents,
            1,
        ) ?? $contents;

        return preg_replace(
            '/^class\s+HoldSignup\s+extends\s+Model$/m',
            'class '.$this->modelClassName().' extends Model',
            $contents,
            1,
        ) ?? $contents;
    }
}
