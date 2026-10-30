<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\Schedule;

/**
 * Every hour, forgets the webhook events kept to be sent again once they
 * are a month old (ADR 0107): past that, a resend would hand the receiver
 * news it acted on long ago, and the event's data -- an order, a customer
 * -- has been kept for nothing. And the delivery log's tries as old beside
 * them (ADR 0116): a try names a delivery whose event is gone, and a
 * tenant's deliveries page shows the newest fifty, so a log kept forever
 * only grew.
 *
 * A task an application lists among its scheduled tasks beside
 * OutboundWebhooks' SentWebhooks. In batches, a bounded number each run, so
 * a store that was never pruned is caught up over a few hours rather than
 * by one statement holding the write lock; an application that keeps them
 * longer or shorter writes a task of its own calling SentWebhooks::prune().
 */
#[Schedule('41 * * * *')]
final class PruneSentWebhooks
{
    /** Thirty days. */
    public const int KEEP = 2_592_000;

    /** Events forgotten at a time. */
    public const int BATCH = 1_000;

    /** Batches a run may forget before it leaves the rest to the next. */
    public const int MOST_BATCHES = 20;

    public function __invoke(SentWebhooks $sent, DeliveryLog $log): void
    {
        $now = time();
        self::prune($sent, $now);
        self::pruneLog($log, $now);
    }

    /**
     * Forgets the events kept longer than KEEP before $now, a batch at a
     * time, MOST_BATCHES at most; answers how many.
     */
    public static function prune(SentWebhooks $sent, int $now): int
    {
        $forgotten = 0;
        for ($batch = 0; $batch < self::MOST_BATCHES; $batch++) {
            $pruned = $sent->prune($now - self::KEEP, self::BATCH);
            $forgotten += $pruned;
            if ($pruned < self::BATCH) {
                break;
            }
        }

        return $forgotten;
    }

    /**
     * Forgets the delivery log's tries made longer than KEEP before $now, as
     * prune() forgets events: a batch at a time, MOST_BATCHES at most;
     * answers how many.
     */
    public static function pruneLog(DeliveryLog $log, int $now): int
    {
        $forgotten = 0;
        for ($batch = 0; $batch < self::MOST_BATCHES; $batch++) {
            $pruned = $log->prune($now - self::KEEP, self::BATCH);
            $forgotten += $pruned;
            if ($pruned < self::BATCH) {
                break;
            }
        }

        return $forgotten;
    }
}
