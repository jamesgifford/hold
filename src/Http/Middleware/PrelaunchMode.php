<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
 * (so the signup/preview endpoints keep working) and any request
 * whose bypass cookie carries the CURRENT activation token (so the team can
 * preview the real app). A cookie from a previous activation no longer matches.
 */
final class PrelaunchMode
{
    public function __construct(
        private readonly HoldState $state,
        private readonly BypassCookie $bypass,
        private readonly ViewFactory $views,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->state->isActive()) {
            return $next($request);
        }

        if ($this->isPackageRoute($request) || $this->hasValidBypass($request)) {
            return $next($request);
        }

        // Resolved through the factory rather than the view() helper: the
        // package's views live under a namespace the helper's view-string type
        // cannot verify, and the factory takes a plain string.
        return response(
            $this->views->make('hold::prelaunch')->render(),
            $this->statusCode(),
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Whether the request carries a bypass cookie whose token matches the
     * current activation. A cookie issued before the last disable/enable no
     * longer matches, so it stops working automatically — no error, just the
     * holding page.
     */
    private function hasValidBypass(Request $request): bool
    {
        $token = $this->state->token();

        if ($token === null) {
            return false;
        }

        $presented = $this->bypass->tokenFromRequest($request);

        return $presented !== null && hash_equals($token, $presented);
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
