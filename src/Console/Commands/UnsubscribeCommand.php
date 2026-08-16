<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JamesGifford\Hold\Hold;

/**
 * Operator tooling to set or clear a signup's unsubscribe state by email.
 *
 * The package respects unsubscribed_at everywhere — every list email (the
 * two announcements and the receipt) carries a signed opt-out link and
 * List-Unsubscribe headers, and the /unsubscribe route is the normal,
 * self-service way an address gets excluded. This command (and the
 * HoldSignup::unsubscribe()/resubscribe() model methods) is the *operator*
 * path for the same thing — set or clear it by email without the address
 * owner clicking anything, e.g. in response to a support request.
 */
final class UnsubscribeCommand extends Command
{
    protected $signature = 'jamesgifford:hold:unsubscribe
        {email : The signup email address to unsubscribe (or resubscribe)}
        {--resubscribe : Clear the unsubscribe state instead of setting it}';

    protected $description = 'Operator tool: unsubscribe (or --resubscribe) a captured signup by email.';

    public function handle(): int
    {
        $argument = $this->argument('email');
        $email = Str::lower(trim(is_string($argument) ? $argument : ''));

        $signup = Hold::signups()->where('email', $email)->first();

        if ($signup === null) {
            $this->error("No signup found for {$email}.");

            return self::FAILURE;
        }

        if ($this->option('resubscribe')) {
            $signup->resubscribe();
            $this->info("Resubscribed {$email}. They are eligible for package emails again.");

            return self::SUCCESS;
        }

        $signup->unsubscribe();
        $this->info("Unsubscribed {$email}. They will receive no package emails until resubscribed.");

        return self::SUCCESS;
    }
}
