<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Http\BypassCookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercepts every request while prelaunch ("coming soon") mode is active and
 * renders the holding page.
 *
 * Registered GLOBALLY by the service provider. When the flag file is absent
 * (the common case) this is a near-zero-cost no-op: a single is_file() check
 * and it hands off to the next middleware. It only ever short-circuits while a
 * hold is deliberately active.
 *
 * Two escape hatches stay reachable during a hold: the package's own routes
 * (so the signup/unsubscribe/preview endpoints keep working) and any request
 * carrying a valid bypass cookie (so the team can preview the real app).
 */
final class PrelaunchMode
{
    public function __construct(
        private readonly HoldState $state,
        private readonly BypassCookie $bypass,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->state->isActive()) {
            return $next($request);
        }

        if ($this->isPackageRoute($request) || $this->bypass->validFromRequest($request)) {
            return $next($request);
        }

        return response(
            view('hold::prelaunch')->render(),
            $this->statusCode(),
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Whether the request targets one of the package's own routes, matched by
     * the configured prefix so the holding page never blocks signup/preview.
     */
    private function isPackageRoute(Request $request): bool
    {
        $prefix = trim((string) config('jamesgifford.hold.routes.prefix', 'hold'), '/');

        if ($prefix === '') {
            return false;
        }

        return $request->is($prefix, $prefix.'/*');
    }

    private function statusCode(): int
    {
        $status = (int) config('jamesgifford.hold.prelaunch.status_code', 200);

        return in_array($status, [200, 503], true) ? $status : 200;
    }
}
