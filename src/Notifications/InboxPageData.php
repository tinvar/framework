<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Data\Data;

/** A page of a user's inbox, newest first, with how many entries are unread in all. */
final readonly class InboxPageData extends Data
{
    /**
     * @param list<InboxEntryData> $entries
     * @param string|null $next the id the next page goes on from, or null for the last page
     */
    public function __construct(
        public array $entries,
        public int $unread,
        public ?string $next,
    ) {}
}
