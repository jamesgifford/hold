<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a genuinely new signup row is captured (never for duplicates,
 * honeypot hits, or throttled submissions). Carries the app-owned Signup model.
 */
final class SignupCaptured
{
    use Dispatchable;

    public function __construct(public readonly Model $signup) {}
}
