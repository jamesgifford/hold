<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use JamesGifford\Hold\Announcements\AnnouncementResult;
use JamesGifford\Hold\Announcements\Announcer;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\SignupContext;

/**
 * Sends a launch/restore announcement, off the queue when one is configured.
 *
 * This is the delayed, "change of mind"-safe path: when dispatched with a delay
 * (auto-announce after a hold ends), it re-checks state on run and aborts
 * SILENTLY if the same hold is active again — so re-enabling the hold within the
 * delay window cancels the announcement without emailing anyone. Immediate,
 * deliberate sends go through the announce command instead.
 */
final class SendAnnouncement implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly SignupContext $context) {}

    public function handle(Announcer $announcer, HoldState $state): AnnouncementResult
    {
        if ($this->holdIsActiveAgain($state)) {
            Log::info('Hold announcement aborted: the hold is active again.', [
                'context' => $this->context->value,
            ]);

            return AnnouncementResult::skipped();
        }

        return $announcer->send($this->context);
    }

    /**
     * Whether the hold this announcement belongs to is currently active — which,
     * for a delayed job, means it was re-enabled after dispatch.
     */
    private function holdIsActiveAgain(HoldState $state): bool
    {
        return $this->context === SignupContext::Maintenance
            ? app()->isDownForMaintenance()
            : $state->isActive();
    }
}
