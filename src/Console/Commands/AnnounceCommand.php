<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\HoldState;

/**
 * Sends the launch/restore announcement to captured signups, immediately.
 *
 * Running the command is deliberate, so it sends now (no delay). It is
 * idempotent — the notified_at guard means a second run never double-sends —
 * and it only ever emails subscribed, verified, not-yet-notified signups of
 * the chosen context. When no --context is given it infers one if exactly
 * one context has pending signups; otherwise it asks you to pick.
 *
 * A real send (not --dry-run) always shows the exact recipient count and asks
 * for confirmation before sending — a mass email is not something to send by
 * accident. --yes skips the prompt for scripted use; a non-interactive run
 * (-n) without --yes refuses rather than silently emailing everyone.
 *
 * --test=<address> rehearses instead: it sends one real, fully rendered
 * announcement to an address of your choosing, without touching any row or
 * needing the prompt above — a way to see exactly what real recipients will
 * get before committing to a send.
 */
final class AnnounceCommand extends Command
{
    protected $signature = 'jamesgifford:hold:announce
        {--context= : Which signups to announce to: prelaunch or maintenance}
        {--dry-run : Report the recipient count per context without sending anything}
        {--yes : Skip the send confirmation prompt (required for a non-interactive run)}
        {--test= : Send one rendered announcement to this address as a rehearsal; touches no rows}';

    protected $description = 'Send the launch/restore announcement to captured signups.';

    public function handle(Announcer $announcer, HoldState $state): int
    {
        if ($this->option('dry-run') && $this->option('test') !== null) {
            $this->error('--dry-run and --test cannot be combined.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->reportCounts($announcer);
        }

        $context = $this->resolveContext($announcer);

        if ($context === false) {
            return self::FAILURE;
        }

        if (($testAddress = $this->option('test')) !== null) {
            return $this->sendTest($announcer, $context, (string) $testAddress);
        }

        if ($context === null) {
            $this->info('No signups are awaiting an announcement. Nothing to send.');

            return self::SUCCESS;
        }

        if ($this->holdActive($context, $state)) {
            $this->warn('Heads up: the '.$context->value.' hold still appears active — sending anyway.');
        }

        if (! $this->confirmSend($announcer, $context)) {
            return self::FAILURE;
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

    /**
     * Show the exact recipient count and get confirmation before sending.
     * --yes skips the prompt; a non-interactive run without it refuses
     * rather than silently emailing everyone.
     */
    private function confirmSend(Announcer $announcer, HoldSignupContext $context): bool
    {
        $count = $announcer->pending($context);
        $this->line("About to email {$count} {$context->value} signup(s).");

        if ($this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to send without confirmation in a non-interactive run. Re-run with --yes to send unattended.');

            return false;
        }

        if (! $this->confirm('Send now?')) {
            $this->warn('Aborted. Nothing was sent.');

            return false;
        }

        return true;
    }

    /**
     * Send one rendered announcement to an arbitrary address, without
     * touching any row. Needs a resolved context — with zero signups
     * pending and no explicit --context, resolveContext() can't infer one,
     * so this asks for it rather than silently doing nothing.
     */
    private function sendTest(Announcer $announcer, ?HoldSignupContext $context, string $address): int
    {
        if ($context === null) {
            $this->error('No context could be inferred (no signups are pending yet). Pass --context explicitly with --test.');

            return self::FAILURE;
        }

        $validator = Validator::make(['email' => $address], [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            $this->error("'{$address}' is not a valid email address.");

            return self::FAILURE;
        }

        $announcer->sendTest($context, $address);

        $this->info("Sent a test {$context->value} announcement to {$address}. No rows were touched.");

        return self::SUCCESS;
    }

    private function holdActive(HoldSignupContext $context, HoldState $state): bool
    {
        return $context === HoldSignupContext::Maintenance
            ? $this->laravel->isDownForMaintenance()
            : $state->isActive();
    }
}
