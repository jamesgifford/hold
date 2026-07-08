<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as BaseMiddleware;

/**
 * Keeps the package's routes reachable while the app is in Laravel's native
 * maintenance mode (`php artisan down`).
 *
 * The service provider binds this class in the container for the framework's
 * PreventRequestsDuringMaintenance, so Laravel resolves this subclass wherever
 * it references the middleware. It merges the package's route URIs (built from
 * the configured prefix) into the excluded-paths list, so the signup /
 * unsubscribe / preview endpoints stay live and the 503 capture form works.
 *
 * Because this is a container binding and nothing else, `composer remove` fully
 * reverses the integration — no host-app core file is ever modified.
 */
final class PreventRequestsDuringMaintenance extends BaseMiddleware
{
    /**
     * Merge the package's route patterns into the framework's excluded paths.
     *
     * @return array<int, string>
     */
    public function getExcludedPaths()
    {
        return array_values(array_unique(array_merge(
            parent::getExcludedPaths(),
            $this->packageExcludedPaths(),
        )));
    }

    /**
     * The package route URIs that must stay reachable during maintenance.
     *
     * @return array<int, string>
     */
    protected function packageExcludedPaths(): array
    {
        $prefix = trim((string) config('jamesgifford.hold.routes.prefix', 'hold'), '/');

        if ($prefix === '') {
            return [];
        }

        return [$prefix, $prefix.'/*'];
    }
}
