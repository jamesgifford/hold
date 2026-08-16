<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Events;

use Illuminate\Foundation\Events\Dispatchable;
use JamesGifford\Hold\Contracts\HoldSignupContract;

/**
 * Fired when a signup confirms ownership of its email address via the
 * /verify link. The package ships no listener for this — it exists purely as
 * an observability hook for the app to build on (analytics, its own
 * downstream automation, etc.).
 */
final class HoldSignupVerified
{
    use Dispatchable;

    public function __construct(public readonly HoldSignupContract $signup) {}
}
