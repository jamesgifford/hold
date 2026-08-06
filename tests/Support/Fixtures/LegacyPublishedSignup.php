<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Tests\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for a model published before HoldSignupContract existed: a real,
 * loadable class that the package must refuse rather than quietly replace with
 * its own — doing so would discard whatever the app had customised.
 */
class LegacyPublishedSignup extends Model
{
    protected $table = 'hold_signups';
}
