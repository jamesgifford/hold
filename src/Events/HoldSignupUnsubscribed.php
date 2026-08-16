<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Hold\Contracts\HoldSignupContract;

/**
 * Fired when a signup opts out via the /unsubscribe link (GET or the RFC 8058
 * one-click POST). The package ships no listener for this — it exists purely
 * as an observability hook for the app to build on.
 */
final class HoldSignupUnsubscribed
{
    use Dispatchable;

    public function __construct(public readonly HoldSignupContract $signup) {}
}
