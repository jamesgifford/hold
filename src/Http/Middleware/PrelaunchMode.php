<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 *
 * A THIRD escape hatch guards against a class of accident rather than a
 * deliberate preview: the flag file lives under storage_path(), which
 * resolves to the SAME physical directory no matter what APP_ENV is. A hold
 * left on from local browsing (enabled to preview a template change, then
 * forgotten) would otherwise silently intercept every HTTP request the
 * consuming app's OWN `testing`-environment test suite makes — nothing about
 * those failures points at Hold. So by default this middleware also no-ops
 * while `app()->environment('testing')`, logging a warning so the situation
 * is at least visible instead of silent. Set `prelaunch.enforce_in_testing`
 * to opt back into real enforcement (this package's own suite does, since it
 * deliberately exercises this middleware under test).
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

        if ($this->bypassForTesting($request)) {
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
     * Whether an active hold should be ignored because we are running inside
     * the `testing` environment and enforcement was not explicitly requested.
     *
     * Logged at warning level (not silent) so a stray flag file left over
     * from local browsing is at least visible in the logs of a consuming
     * app's test run, rather than presenting as unrelated, disconnected test
     * failures with nothing pointing back at Hold.
     */
    private function bypassForTesting(Request $request): bool
    {
        if (! app()->environment('testing')) {
            return false;
        }

        if ((bool) config('jamesgifford.hold.prelaunch.enforce_in_testing', false)) {
            return false;
        }

        Log::warning('Hold: an active prelaunch hold was bypassed for this request because APP_ENV is `testing` and `prelaunch.enforce_in_testing` is off. If a flag file was left over from local browsing, run `php artisan jamesgifford:hold:disable`; if you meant to exercise the holding page under test, set `prelaunch.enforce_in_testing` to true.', [
            'path' => $request->path(),
        ]);

        return true;
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
