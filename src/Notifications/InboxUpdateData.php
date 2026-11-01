<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Data\Data;

/**
 * What an inbox's personal channel says when the user's feed changes: how
 * many entries are unread now, and the entry that arrived, or the ids
 * another tab marked read -- every one, when $allRead.
 */
final readonly class InboxUpdateData extends Data
{
    /** @param list<string> $read */
    public function __construct(
        public int $unread,
        public ?InboxEntryData $entry = null,
        public array $read = [],
        public bool $allRead = false,
    ) {}
}
