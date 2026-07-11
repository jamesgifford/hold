<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Announcements;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use JamesGifford\Hold\Hold;
use JamesGifford\Hold\HoldSignupContext;
use Throwable;

/**
 * Sends launch/restore announcements to captured signups.
 *
 * Targets only subscribed, not-yet-notified signups of the given context and
 * stamps notified_at as each is sent — so re-running is safe and never
 * double-sends. Emails go out via on-demand mail routes (no User model), in
 * chunks so a large list stays memory-bounded.
 */
final class Announcer
{
    /**
     * Config key of the notification each context announces with.
     */
    private const NOTIFICATION_KEY = [
        HoldSignupContext::Prelaunch->value => 'launch_announcement',
        HoldSignupContext::Maintenance->value => 'service_restored',
    ];

    /**
     * Count the signups that a run for this context would email.
     */
    public function pending(HoldSignupContext $context): int
    {
        return $this->targets($context)->count();
    }

    /**
     * Send the announcement for a context, stamping each recipient. Returns the
     * per-recipient tallies.
     */
    public function send(HoldSignupContext $context): AnnouncementResult
    {
        $class = Hold::notificationClass(self::NOTIFICATION_KEY[$context->value]);
        $sent = 0;
        $failed = 0;

        $this->targets($context)->chunkById(200, function ($signups) use ($class, &$sent, &$failed): void {
            foreach ($signups as $signup) {
                try {
                    Notification::route('mail', $signup->email)->notify(new $class($signup));
                    $signup->forceFill(['notified_at' => Carbon::now()])->save();
                    $sent++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('Hold announcement failed for a signup.', [
                        'signup_id' => $signup->getKey(),
                        'context' => $context->value,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Hold announcement run complete.', [
            'context' => $context->value,
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return new AnnouncementResult(sent: $sent, failed: $failed);
    }

    /**
     * Subscribed, not-yet-notified signups for the given context.
     */
    private function targets(HoldSignupContext $context)
    {
        return Hold::signups()
            ->subscribed()
            ->notNotified()
            ->context($context);
    }
}
