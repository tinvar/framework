<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Carbon\CarbonImmutable;

/** Inbox entries in memory, for tests and a single process. */
final class ArrayInboxStore implements InboxStore
{
    /** @var array<string, InboxEntry> by id */
    public array $entries = [];

    public function add(InboxEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function page(string $tenant, string $user, int $limit, ?string $before = null): array
    {
        $found = array_filter(
            $this->entries,
            static fn (InboxEntry $e): bool => $e->tenant === $tenant && $e->user === $user && ($before === null || strcmp($e->id, $before) < 0),
        );
        krsort($found, SORT_STRING);

        return \array_slice(array_values($found), 0, max(0, $limit));
    }

    public function unread(string $tenant, string $user): int
    {
        return \count(array_filter($this->entries, static fn (InboxEntry $e): bool => $e->tenant === $tenant && $e->user === $user && $e->readAt === null));
    }

    public function markRead(string $tenant, string $user, ?array $ids, CarbonImmutable $at): int
    {
        $marked = 0;
        foreach ($this->entries as $id => $entry) {
            if ($entry->tenant !== $tenant || $entry->user !== $user || $entry->readAt !== null || ($ids !== null && !\in_array($id, $ids, true))) {
                continue;
            }
            $this->entries[$id] = new InboxEntry($entry->id, $entry->tenant, $entry->user, $entry->type, $entry->message, $entry->createdAt, $at);
            $marked++;
        }

        return $marked;
    }

    public function prune(CarbonImmutable $before): int
    {
        $count = \count($this->entries);
        $this->entries = array_filter($this->entries, static fn (InboxEntry $e): bool => $e->createdAt >= $before);

        return $count - \count($this->entries);
    }
}
