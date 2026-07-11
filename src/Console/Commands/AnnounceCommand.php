<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;

/**
 * Sends the launch/restore announcement to captured signups, immediately.
 *
 * Running the command is deliberate, so it sends now (no delay). It is
 * idempotent — the notified_at guard means a second run never double-sends —
 * and it only ever emails subscribed, not-yet-notified signups of the chosen
 * context. When no --context is given it infers one if exactly one context has
 * pending signups; otherwise it asks you to pick.
 */
final class AnnounceCommand extends Command
{
    protected $signature = 'jamesgifford:hold:announce
        {--context= : Which signups to announce to: prelaunch or maintenance}
        {--dry-run : Report the recipient count per context without sending anything}';

    protected $description = 'Send the launch/restore announcement to captured signups.';

    public function handle(Announcer $announcer, HoldState $state): int
    {
        if ($this->option('dry-run')) {
            return $this->reportCounts($announcer);
        }

        $context = $this->resolveContext($announcer);

        if ($context === false) {
            return self::FAILURE;
        }

        if ($context === null) {
            $this->info('No signups are awaiting an announcement. Nothing to send.');

            return self::SUCCESS;
        }

        if ($this->holdActive($context, $state)) {
            $this->warn('Heads up: the '.$context->value.' hold still appears active — sending anyway.');
        }

        $result = $announcer->send($context);

        $this->info("Announced to {$result->sent} signup(s) for the {$context->value} context.");
        if ($result->failed > 0) {
            $this->warn("{$result->failed} send(s) failed — see the logs.");
        }

        return self::SUCCESS;
    }

    /**
     * Print the pending count for each context and send nothing.
     */
    private function reportCounts(Announcer $announcer): int
    {
        $this->info('Signups awaiting an announcement (dry run — nothing sent):');
        foreach (HoldSignupContext::cases() as $context) {
            $this->line(sprintf('  %-12s %d', $context->value, $announcer->pending($context)));
        }

        return self::SUCCESS;
    }

    /**
     * @return HoldSignupContext|null|false context to send, null for nothing-to-do,
     *                                      false for an error already reported.
     */
    private function resolveContext(Announcer $announcer): HoldSignupContext|null|false
    {
        $option = $this->option('context');

        if ($option !== null) {
            $context = HoldSignupContext::tryFrom((string) $option);

            if ($context === null) {
                $this->error("Invalid --context '{$option}'. Use 'prelaunch' or 'maintenance'.");

                return false;
            }

            return $context;
        }

        // Infer: use the sole context with pending signups, else disambiguate.
        $pending = array_filter(
            array_combine(
                array_map(fn (HoldSignupContext $c) => $c->value, HoldSignupContext::cases()),
                array_map(fn (HoldSignupContext $c) => $announcer->pending($c), HoldSignupContext::cases()),
            ),
        );

        if ($pending === []) {
            return null;
        }

        if (count($pending) > 1) {
            $this->error('Both contexts have signups awaiting an announcement. Re-run with --context=prelaunch or --context=maintenance.');

            return false;
        }

        return HoldSignupContext::from((string) array_key_first($pending));
    }

    private function holdActive(HoldSignupContext $context, HoldState $state): bool
    {
        return $context === HoldSignupContext::Maintenance
            ? $this->laravel->isDownForMaintenance()
            : $state->isActive();
    }
}
