<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http;

use Illuminate\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * The app's `except` list: requests that pass straight through BOTH hold
 * modes, for machine-to-machine endpoints (payment webhooks above all) that
 * must never be answered with a holding page.
 *
 * Shared by PrelaunchMode and the maintenance middleware subclass so the two
 * modes can never disagree about what is excluded.
 *
 * Paths (`except.paths`) go through Laravel's own ExcludesPaths concern, so
 * they match exactly the way the framework's maintenance and CSRF `except`
 * lists do — same wildcard, slash-trimming and full-URL rules.
 *
 * Route names (`except.routes`) cannot simply ask `$request->routeIs()`: both
 * hold middlewares are GLOBAL and run before routing, so the request has no
 * route yet. Each named route is matched against the request directly
 * instead, which walks the route table — callers only pay for it while a
 * hold is actually active.
 */
final class ExcludedRequests
{
    use ExcludesPaths;

    public function __construct(
        private readonly Router $router,
    ) {}

    /**
     * Whether the request matches a configured path or route name.
     */
    public function matches(Request $request): bool
    {
        return $this->inExceptArray($request) || $this->matchesRouteName($request);
    }

    /**
     * The configured excluded paths (`except.paths`). Public because the
     * maintenance subclass merges them into the framework's own list.
     *
     * @return array<int, string>
     */
    public function getExcludedPaths(): array
    {
        return $this->strings(config('jamesgifford.hold.except.paths', []));
    }

    /**
     * Whether any route names are excluded at all — lets a caller skip the
     * route-table walk (and anything it would do first) when there are none.
     */
    public function hasRouteNames(): bool
    {
        return $this->routeNames() !== [];
    }

    /**
     * Whether the request targets a route whose name is excluded. A named
     * route only counts when it would actually handle this request — URI,
     * HTTP method and domain included — so a GET to a POST-only webhook URI
     * stays held.
     *
     * The live route table is read rather than its name index, which only
     * reflects names given before the index was last refreshed.
     */
    public function matchesRouteName(Request $request): bool
    {
        $names = $this->routeNames();

        if ($names === []) {
            return false;
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && Str::is($names, $name) && $route->matches($request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function routeNames(): array
    {
        return $this->strings(config('jamesgifford.hold.except.routes', []));
    }

    /**
     * Normalize a configured list to its non-empty strings, so a stray null or
     * a single string in place of an array never errors mid-hold.
     *
     * @return array<int, string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(
            (array) $values,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }
}
