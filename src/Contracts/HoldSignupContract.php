<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JamesGifford\Hold\HoldSignupContext;

/**
 * The shape the package requires of whatever `models.signup` points at.
 *
 * The signup model is PUBLISHED into the host app, so the class the package
 * resolves is not a subclass of the package's own — it is an independent copy
 * that the app owns. This interface is what makes that arrangement checkable:
 * the publish step rewrites only the namespace and class name, so the copy keeps
 * the `implements` clause and its import, and both classes satisfy the same
 * contract.
 *
 * @property int $id
 * @property string $email
 * @property HoldSignupContext $context
 * @property Carbon $requested_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $notified_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @phpstan-require-extends Model
 */
interface HoldSignupContract
{
    /**
     * Soft-unsubscribe this address. Idempotent.
     */
    public function unsubscribe(): void;

    /**
     * Clear the unsubscribe state. Idempotent.
     */
    public function resubscribe(): void;
}
