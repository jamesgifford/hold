<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JamesGifford\Hold\Contracts\HoldSignupContract;
use JamesGifford\Hold\Database\Factories\HoldSignupFactory;
use JamesGifford\Hold\HoldSignupContext;

/**
 * A captured email signup for a hold (prelaunch or maintenance).
 *
 * This model is PUBLISHED into the host app as App\Models\HoldSignup (the app
 * owns it — edit it freely). The package resolves it through
 * config('jamesgifford.hold.models.signup'), so pointing that at a subclass
 * swaps behavior without touching the package.
 *
 * Unsubscribe is a soft state: the package keeps the unsubscribed_at column,
 * fully respects it (the subscribed() scope excludes it from every package
 * email), and sets it via the /unsubscribe route reached from a signed link
 * in every list email (or the `jamesgifford:hold:unsubscribe` operator
 * command / {@see unsubscribe()} directly) — but only ever CLEARS it via a
 * successful /verify click ({@see resubscribe()}), proof the mailbox owner
 * initiated the re-subscribe. The package never clears it on its own.
 *
 * @property int $id
 * @property string $email
 * @property HoldSignupContext $context
 * @property Carbon $requested_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $notified_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['email', 'context', 'requested_at', 'verified_at', 'ip_address', 'user_agent'])]
class HoldSignup extends Model implements HoldSignupContract
{
    /** @use HasFactory<HoldSignupFactory> */
    use HasFactory;

    protected $table = 'hold_signups';

    /**
     * Soft-unsubscribe this address. Idempotent: an already-unsubscribed row
     * keeps its original timestamp. For the app to call from its own opt-out
     * features (or via `jamesgifford:hold:unsubscribe`).
     */
    public function unsubscribe(): void
    {
        if ($this->unsubscribed_at === null) {
            $this->unsubscribed_at = Carbon::now();
            $this->save();
        }
    }

    /**
     * Clear the unsubscribe state, restoring eligibility for package emails.
     * Idempotent. For the app to call (or via `jamesgifford:hold:unsubscribe
     * --resubscribe`).
     */
    public function resubscribe(): void
    {
        if ($this->unsubscribed_at !== null) {
            $this->unsubscribed_at = null;
            $this->save();
        }
    }

    /**
     * Confirm this address's ownership. Idempotent: an already-verified row
     * keeps its original timestamp. The announcer only ever emails verified
     * rows — see {@see verified()}.
     */
    public function markVerified(): void
    {
        if ($this->verified_at === null) {
            $this->verified_at = Carbon::now();
            $this->save();
        }
    }

    /**
     * Addresses that have not yet been sent their announcement.
     */
    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notNotified(Builder $query): void
    {
        $query->whereNull('notified_at');
    }

    /**
     * Addresses that are still subscribed (never unsubscribed).
     */
    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function subscribed(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }

    /**
     * Addresses whose ownership has been confirmed — or, when
     * verification.required is off, stamped verified at capture time.
     */
    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }

    /**
     * Restrict to a single capture context (prelaunch or maintenance).
     */
    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function context(Builder $query, HoldSignupContext|string $context): void
    {
        $query->where('context', $context instanceof HoldSignupContext ? $context->value : $context);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => HoldSignupContext::class,
            'requested_at' => 'datetime',
            'notified_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): HoldSignupFactory
    {
        return HoldSignupFactory::new();
    }
}
