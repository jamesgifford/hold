<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as BaseMiddleware;
use Illuminate\Http\Request;
use JamesGifford\Hold\Http\ExcludedRequests;

/**
 * Keeps the package's routes — and the app's `except` list — reachable while
 * the app is in Laravel's native maintenance mode (`php artisan down`).
 *
 * The service provider binds this class in the container for the framework's
 * PreventRequestsDuringMaintenance, so Laravel resolves this subclass wherever
 * it references the middleware. It merges the package's route URIs (built from
 * the configured prefix) and the configured `except.paths` into the
 * excluded-paths list, so the signup / preview endpoints stay live, the 503
 * capture form works, and excluded endpoints (webhooks) keep being served.
 * Excluded route names (`except.routes`) are checked alongside — see
 * inExceptArray().
 *
 * Because this is a container binding and nothing else, `composer remove` fully
 * reverses the integration — no host-app core file is ever modified.
 */
final class PreventRequestsDuringMaintenance extends BaseMiddleware
{
    /**
     * Merge the package's route patterns and the configured excluded paths
     * into the framework's excluded paths.
     *
     * @return array<int, string>
     */
    public function getExcludedPaths()
    {
        return array_values(array_unique(array_merge(
            parent::getExcludedPaths(),
            $this->packageExcludedPaths(),
            $this->exclusions()->getExcludedPaths(),
        )));
    }

    /**
     * Also let excluded route names through.
     *
     * The framework consults this on EVERY request, before it even asks
     * whether maintenance is active, and matching a route name walks the
     * route table — so that part only runs while the app is actually down.
     *
     * @param  Request  $request
     * @return bool
     */
    protected function inExceptArray($request)
    {
        if (parent::inExceptArray($request)) {
            return true;
        }

        $exclusions = $this->exclusions();

        return $exclusions->hasRouteNames()
            && $this->app->maintenanceMode()->active()
            && $exclusions->matchesRouteName($request);
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

    private function exclusions(): ExcludedRequests
    {
        return $this->app->make(ExcludedRequests::class);
    }
}
