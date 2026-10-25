<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * The uploads /_upload has stored, until an action claims each or it runs
 * out and is pruned.
 *
 * A claim is recorded, not a deletion: a claimed upload's file belongs to
 * whatever record the action attached it to, and pruning never touches it.
 */
interface UploadStore
{
    public function add(PendingUpload $upload): void;

    public function find(string $id): ?PendingUpload;

    /** How many uploads of the user $ownerHash names (Uploads::ownerHash()) are unclaimed and not yet expired. */
    public function pendingCount(string $ownerHash, int $now): int;

    /**
     * Marks an upload claimed, if it is unclaimed and has not expired.
     * Atomic: of two calls claiming one upload, one is told true.
     */
    public function claim(string $id, int $now): bool;

    /** Unclaims an upload, for an action whose body failed after claiming it. */
    public function release(string $id): void;

    /**
     * Unclaimed uploads that have expired, oldest first.
     *
     * @return list<PendingUpload>
     */
    public function expired(int $now, int $limit): array;

    /** Forgets an upload entirely. */
    public function forget(string $id): void;

    /** Forgets every claim made before $before; returns how many. Their files are not touched. */
    public function forgetClaimedBefore(int $before): int;
}
