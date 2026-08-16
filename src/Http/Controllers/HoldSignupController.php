<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JamesGifford\Hold\Events\HoldSignupCaptured;
use JamesGifford\Hold\Hold;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Support\Verification;

/**
 * Captures a public email signup from either holding page.
 *
 * Deliberately quiet: a bot-tripped honeypot, an over-the-limit IP, a same-cycle
 * duplicate, and a genuinely new signup all return the SAME success response, so
 * the endpoint never reveals whether an address is already on the list. Feedback
 * travels back as a `?hold=` query param (see the views for why session/flash
 * can't be relied on here).
 *
 * One row per email. Duplicate submissions are lifecycle-aware:
 *  - same cycle (row not yet notified): write NOTHING — the row stays byte-identical;
 *  - new hold (row already notified): re-arm it (reset notified_at/requested_at,
 *    set the current context, refresh ip/ua) — never touching unsubscribed_at.
 */
final class HoldSignupController
{
    public function store(Request $request, HoldState $state): RedirectResponse
    {
        $honeypot = (string) config('jamesgifford.hold.spam.honeypot_field', 'website');

        // A filled honeypot means a bot: succeed silently, store nothing.
        if (filled($request->input($honeypot))) {
            return $this->back($request, 'subscribed');
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->back($request, 'invalid');
        }

        // Per-IP rate limit. Over the limit also succeeds silently — no reveal,
        // no write — so a flood can't grow the list.
        $key = 'jamesgifford-hold-signup:'.$request->ip();
        $max = max(1, (int) config('jamesgifford.hold.spam.rate_limit_per_minute', 5));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return $this->back($request, 'subscribed');
        }

        RateLimiter::hit($key, 60);

        $this->capture($request, $state);

        return $this->back($request, 'subscribed');
    }

    /**
     * Create, re-arm, or no-op the row for this email, then fire HoldSignupCaptured
     * for genuinely new or re-armed rows (never for a same-cycle duplicate).
     */
    private function capture(Request $request, HoldState $state): void
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $context = $this->resolveContext($request, $state);
        $existing = Hold::signups()->where('email', $email)->first();

        if ($existing === null) {
            $signup = Hold::signups()->create([
                'email' => $email,
                'context' => $context,
                'requested_at' => Carbon::now(),
                // Stamped now when verification isn't required, so a row is
                // never permanently unreachable regardless of which way this
                // config is set; left null otherwise for
                // SendSignupVerification to act on.
                'verified_at' => $this->verificationRequired() ? null : Carbon::now(),
                'ip_address' => $request->ip(),
                'user_agent' => $this->userAgent($request),
            ]);

            event(new HoldSignupCaptured($signup));

            return;
        }

        // Same cycle (not yet notified): leave the row byte-identical. An
        // unverified row still gets a fresh verification email — sent
        // directly, not via HoldSignupCaptured (nothing about the row
        // changed) — so a signup that missed or lost the first link isn't
        // stuck; the per-IP rate limit above is its only throttle.
        if ($existing->notified_at === null) {
            if ($existing->verified_at === null) {
                Verification::send($existing);
            }

            return;
        }

        // A new hold: re-arm. Never touch unsubscribed_at in either
        // direction, and never touch verified_at — ownership, once proven,
        // carries forward across every later hold.
        $existing->forceFill([
            'context' => $context,
            'requested_at' => Carbon::now(),
            'notified_at' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $this->userAgent($request),
        ])->save();

        event(new HoldSignupCaptured($existing));
    }

    private function verificationRequired(): bool
    {
        return (bool) config('jamesgifford.hold.verification.required', true);
    }

    private function userAgent(Request $request): string
    {
        return Str::limit((string) $request->userAgent(), 500, '');
    }

    /**
     * Determine which hold captured this signup. Server-side detection wins over
     * the posted hidden field: maintenance first (the app is literally down),
     * then an active prelaunch hold, then the posted context as a fallback.
     */
    private function resolveContext(Request $request, HoldState $state): HoldSignupContext
    {
        if (app()->isDownForMaintenance()) {
            return HoldSignupContext::Maintenance;
        }

        if ($state->isActive()) {
            return HoldSignupContext::Prelaunch;
        }

        return HoldSignupContext::tryFrom((string) $request->input('context'))
            ?? HoldSignupContext::Prelaunch;
    }

    /**
     * Redirect back to the page the form was submitted from, carrying the
     * outcome as a `?hold=` query param.
     */
    private function back(Request $request, string $status): RedirectResponse
    {
        return redirect()->to($this->refererPath($request).'?hold='.$status);
    }

    /**
     * The same-origin path the form was submitted from, or '/' when the Referer
     * is absent, cross-host, or not a plain rooted path.
     *
     * Referer is attacker-influenced and, while a hold is active, the holding
     * page renders at EVERY path — so a crafted same-host URL is a reachable way
     * to reach this code. The result is therefore allow-listed, not sanitised:
     * anything that is not a single-slash-rooted path collapses to the root.
     */
    private function refererPath(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        if ($referer === '') {
            return '/';
        }

        $parts = parse_url($referer);

        if ($parts === false) {
            return '/';
        }

        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return '/';
        }

        $path = (string) ($parts['path'] ?? '');

        if (! str_starts_with($path, '/')) {
            return '/';
        }

        // parse_url() reports a leading '//host' as the PATH, not the host, so
        // the same-host check above passes for it. Both '//host' and '/\host'
        // are resolved by browsers as absolute URLs to another origin, and
        // Laravel's UrlGenerator::to() passes a leading '//' straight through.
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return '/';
        }

        return $path;
    }
}
