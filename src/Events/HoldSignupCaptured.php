<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Hold\Contracts\HoldSignupContract;

/**
 * Fired when a signup row is armed for the current hold — a genuinely new row or
 * a re-armed one (a previously-notified row re-requesting for a new hold). NOT
 * fired for a same-cycle duplicate, honeypot hit, or throttled submission.
 * Carries the app-owned HoldSignup model.
 */
final class HoldSignupCaptured
{
    use Dispatchable;

    public function __construct(public readonly HoldSignupContract $signup) {}
}
