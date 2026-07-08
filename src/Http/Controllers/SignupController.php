<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JamesGifford\Hold\Events\SignupCaptured;
use JamesGifford\Hold\Hold;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\SignupContext;

/**
 * Captures a public email signup from either holding page.
 *
 * Deliberately quiet: a bot-tripped honeypot, an over-the-limit IP, and a
 * duplicate address all return the SAME success response as a genuine new
 * signup, so the endpoint never reveals whether an address is already on the
 * list. Feedback travels back as a `?hold=` query param (see the views for why
 * session/flash can't be relied on here).
 */
final class SignupController
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

        $email = Str::lower(trim((string) $request->input('email')));
        $context = $this->resolveContext($request, $state);
        $model = Hold::signupModel();

        $signup = $model::query()->firstOrCreate(
            ['email' => $email],
            [
                'context' => $context,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ],
        );

        // Fire only for genuinely new rows so downstream receipts never double-send.
        if ($signup->wasRecentlyCreated) {
            event(new SignupCaptured($signup));
        }

        return $this->back($request, 'subscribed');
    }

    /**
     * Determine which hold captured this signup. Server-side detection wins over
     * the posted hidden field: maintenance first (the app is literally down),
     * then an active prelaunch hold, then the posted context as a fallback.
     */
    private function resolveContext(Request $request, HoldState $state): SignupContext
    {
        if (app()->isDownForMaintenance()) {
            return SignupContext::Maintenance;
        }

        if ($state->isActive()) {
            return SignupContext::Prelaunch;
        }

        return SignupContext::tryFrom((string) $request->input('context'))
            ?? SignupContext::Prelaunch;
    }

    /**
     * Redirect back to the page the form was submitted from, carrying the
     * outcome as a `?hold=` query param. The target is reduced to a same-origin
     * path so a spoofed Referer can't turn this into an open redirect.
     */
    private function back(Request $request, string $status): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = '/';

        if ($referer !== '') {
            $parts = parse_url($referer);
            $sameHost = ! isset($parts['host']) || $parts['host'] === $request->getHost();

            if ($sameHost && isset($parts['path']) && $parts['path'] !== '') {
                $path = $parts['path'];
            }
        }

        return redirect()->to($path.'?hold='.$status);
    }
}
