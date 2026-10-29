<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Orm\Connection;

/** Transfers in memory: for tests, and an application with one process. */
final class ArrayTransferStore implements TransferStore
{
    /** @var array<string, TransferRecord> */
    private array $records = [];

    public function add(TransferRecord $record): void
    {
        $this->records[$record->id] = $record;
    }

    public function find(string $id, string $tenant, string $panel, string $user): ?TransferRecord
    {
        $record = $this->records[$id] ?? null;

        return $record !== null && $record->tenant === $tenant && $record->panel === $panel && $record->user === $user
            ? $record
            : null;
    }

    public function claim(string $id): bool
    {
        $record = $this->records[$id] ?? null;
        if ($record === null || $record->status !== TransferRecord::QUEUED) {
            return false;
        }
        $this->records[$id] = $record->with(['status' => TransferRecord::RUNNING]);

        return true;
    }

    public function update(TransferRecord $record): void
    {
        if (isset($this->records[$record->id])) {
            $this->records[$record->id] = $record;
        }
    }

    public function recent(string $tenant, string $panel, string $user, int $limit): array
    {
        $mine = array_filter(
            $this->records,
            static fn (TransferRecord $r): bool => $r->tenant === $tenant && $r->panel === $panel && $r->user === $user,
        );
        usort($mine, static fn (TransferRecord $a, TransferRecord $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return \array_slice($mine, 0, max(0, $limit));
    }

    public function expired(int $now, int $createdBefore, int $limit): array
    {
        $old = array_filter(
            $this->records,
            static fn (TransferRecord $r): bool => ($r->expiresAt > 0 && $r->expiresAt <= $now) || $r->createdAt < $createdBefore,
        );
        usort($old, static fn (TransferRecord $a, TransferRecord $b): int => $a->createdAt <=> $b->createdAt);

        return \array_slice($old, 0, max(0, $limit));
    }

    public function forget(string $id): void
    {
        unset($this->records[$id]);
    }

    public function enlistsIn(Connection $connection): bool
    {
        return false;
    }

    /** @return list<TransferRecord> every record, for a test */
    public function all(): array
    {
        return array_values($this->records);
    }
}
