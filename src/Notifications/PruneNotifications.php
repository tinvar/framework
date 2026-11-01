<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\Schedule;

/**
 * Nightly, removes every inbox entry older than Notifications::KEEP_ENTRIES,
 * read or not: an inbox is what happened lately, not an archive. Added to
 * an application's tasks by its Kernel, as PruneQueue is.
 */
#[Schedule('41 3 * * *')]
final class PruneNotifications
{
    public function __invoke(InboxStore $store): void
    {
        self::prune($store, CarbonImmutable::now('UTC'));
    }

    /** Prunes what was written KEEP_ENTRIES before $now; how many went. */
    public static function prune(InboxStore $store, CarbonImmutable $now): int
    {
        return $store->prune($now->subSeconds(Notifications::KEEP_ENTRIES));
    }
}
