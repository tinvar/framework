<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;

/**
 * Where users' inbox entries are kept (ADR 0154).
 *
 * Every read and write names the tenant claim and the user, whatever else
 * it names: an entry's id from a request finds nothing of another user's,
 * or of the same user's in another tenant, so marking it read changes
 * nothing. Only prune() reaches across them, as maintenance does.
 */
interface InboxStore
{
    public function add(InboxEntry $entry): void;

    /**
     * $user's entries in $tenant, newest first: $limit of them, older than
     * the entry $before names when it is given.
     *
     * @return list<InboxEntry>
     */
    public function page(string $tenant, string $user, int $limit, ?string $before = null): array;

    /** How many of $user's entries in $tenant are unread. */
    public function unread(string $tenant, string $user): int;

    /**
     * Marks $user's entries in $tenant read at $at: those $ids names, or
     * every one for null. Entries of anyone else's, or already read, are
     * left as they are.
     *
     * @param list<string>|null $ids
     * @return int how many it marked
     */
    public function markRead(string $tenant, string $user, ?array $ids, CarbonImmutable $at): int;

    /** Removes every entry, in any tenant, written before $before; how many. */
    public function prune(CarbonImmutable $before): int;
}
