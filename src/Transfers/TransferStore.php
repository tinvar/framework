<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Orm\Connection;

/**
 * The exports and imports users have started, until they are pruned.
 *
 * Not tenant-scoped as a model is: the tenant claim, the panel and the user
 * are columns every read names, so a record is found only by the context
 * it was started in -- and pruning reads every tenant's at once.
 */
interface TransferStore
{
    public function add(TransferRecord $record): void;

    /**
     * The record with $id, started in exactly this tenant claim and panel
     * by exactly this user; null for any other.
     */
    public function find(string $id, string $tenant, string $panel, string $user): ?TransferRecord;

    /**
     * Marks a queued record running, if it is still queued. Atomic: of two
     * workers handed one job, one is told true, and only it does the work.
     */
    public function claim(string $id): bool;

    /** Replaces the record with $record's id; nothing for none. */
    public function update(TransferRecord $record): void;

    /**
     * The newest $limit records started in this tenant claim and panel by
     * this user, newest first.
     *
     * @return list<TransferRecord>
     */
    public function recent(string $tenant, string $panel, string $user, int $limit): array;

    /**
     * Records whose file has expired, or that were created before
     * $createdBefore, oldest first.
     *
     * @return list<TransferRecord>
     */
    public function expired(int $now, int $createdBefore, int $limit): array;

    public function forget(string $id): void;

    /**
     * Whether a write here runs on $connection, inside whatever transaction
     * it has open: so an import's progress, written in the transaction that
     * writes its rows, commits or rolls back with them, and a run cut off
     * anywhere picks up after the last rows that committed -- never writing
     * one twice. A store elsewhere says no, and an import there writes all
     * its rows in one transaction (TransferRunner::import()).
     */
    public function enlistsIn(Connection $connection): bool;
}
