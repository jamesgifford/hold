<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JamesGifford\Hold\Hold;

/**
 * Operator tooling to set or clear a signup's unsubscribe state by email.
 *
 * Unsubscribe is an app-owned data contract: the package respects unsubscribed_at
 * everywhere but ships NO public/user-facing way to set it. This command (and the
 * HoldSignup::unsubscribe()/resubscribe() model methods) are the only means, invoked
 * deliberately by a server operator or the app's own features.
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
