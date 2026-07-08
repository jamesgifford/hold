<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JamesGifford\Hold\Database\Factories\SignupFactory;
use JamesGifford\Hold\SignupContext;

/**
 * A captured email signup for a hold (prelaunch or maintenance).
 *
 * This model is PUBLISHED into the host app (the app owns it — edit it freely).
 * The package resolves it through config('jamesgifford.hold.models.signup'), so
 * pointing that at a subclass swaps behavior without touching the package.
 *
 * Unsubscribe is a soft state ({@see unsubscribe()}): rows are never deleted, so
 * an address keeps its history across resubscribes and its notified_at guard
 * still prevents a re-announcement.
 *
 * @property int $id
 * @property string $email
 * @property SignupContext $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $notified_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['email', 'context', 'ip_address', 'user_agent'])]
class Signup extends Model
{
    use HasFactory;

    protected $table = 'hold_signups';

    /**
     * Soft-unsubscribe this address. Idempotent: an already-unsubscribed row
     * keeps its original timestamp.
     */
    public function unsubscribe(): void
    {
        if ($this->unsubscribed_at === null) {
            $this->unsubscribed_at = Carbon::now();
            $this->save();
        }
    }

    /**
     * Addresses that have not yet been sent their announcement.
     */
    #[Scope]
    protected function notNotified(Builder $query): void
    {
        $query->whereNull('notified_at');
    }

    /**
     * Addresses that are still subscribed (never unsubscribed).
     */
    #[Scope]
    protected function subscribed(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }

    /**
     * Restrict to a single capture context (prelaunch or maintenance).
     */
    #[Scope]
    protected function context(Builder $query, SignupContext|string $context): void
    {
        $query->where('context', $context instanceof SignupContext ? $context->value : $context);
    }

    protected function casts(): array
    {
        return [
            'context' => SignupContext::class,
            'notified_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SignupFactory
    {
        return SignupFactory::new();
    }
}
