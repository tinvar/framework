<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

/** The outbox in memory, for tests and for an application with no gateway yet. */
final class ArrayCommandOutbox implements CommandOutbox
{
    /** @var list<OutboxEntry> */
    private array $entries = [];

    public function append(NodeCommand $command, string $sealed): void
    {
        $this->entries[] = new OutboxEntry($command->id, $command->node, $sealed, $command->issuedAt);
    }

    public function since(int $since, int $limit = 100): array
    {
        $found = array_values(array_filter($this->entries, static fn (OutboxEntry $entry): bool => $entry->createdAt >= $since));
        usort($found, static fn (OutboxEntry $a, OutboxEntry $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return \array_slice($found, 0, max(1, $limit));
    }

    public function find(string $id): ?OutboxEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }
}
