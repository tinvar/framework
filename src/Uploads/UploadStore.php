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

    /**
     * Adds an upload only if its owner has fewer than $max unclaimed uploads
     * not yet pruned, past their expiry or not (pendingCount()) -- and,
     * given a quota, holds no more than $quotaBytes with this one
     * (bytesHeld()) -- counted and added as one write: of uploads sent at
     * once, no more are kept than the cap and the quota allow, however they
     * race.
     *
     * @param int $now when it is added; neither count reads it, since an
     *        upload past its expiry counts until pruning removes its file
     * @return bool whether it was added; false when the owner is at the cap or the quota
     */
    public function addWithin(PendingUpload $upload, int $max, int $now, ?int $quotaBytes = null): bool;

    /**
     * The bytes the user $ownerHash names holds: every upload of theirs
     * claimed and not yet given up (Uploads::discard()), and every one
     * unclaimed and not yet pruned, past its expiry or not -- its file is
     * kept until then. What a quota counts.
     */
    public function bytesHeld(string $ownerHash, int $now): int;

    /** Forgets the upload whose file is at $key, claimed or not: its file is gone. */
    public function forgetKey(string $key): void;

    public function find(string $id): ?PendingUpload;

    /**
     * How many uploads of the user $ownerHash names (Uploads::ownerHash())
     * are unclaimed and not yet pruned. One past its expiry still counts:
     * its file is kept until pruning removes it, and a cap that let it go
     * at its expiry let a user keep files waiting several times over.
     */
    public function pendingCount(string $ownerHash, int $now): int;

    /**
     * Marks an upload claimed, if it is unclaimed and has not expired.
     * Atomic: of two calls claiming one upload, one is told true.
     */
    public function claim(string $id, int $now): bool;

    /** Unclaims an upload, for an action whose body failed after claiming it. */
    public function release(string $id): void;

    /**
     * Unclaimed uploads that have expired, oldest first -- and resumable
     * sessions (of no type) past their expiry, claimed or not: a claim on
     * one is a completion in flight, and one still held past its expiry was
     * left by a process that died holding it.
     *
     * @return list<PendingUpload>
     */
    public function expired(int $now, int $limit): array;

    /** Forgets an upload entirely. */
    public function forget(string $id): void;

    /** Forgets every claim made before $before; returns how many. Their files are not touched. */
    public function forgetClaimedBefore(int $before): int;
}
