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
    /**
     * How long a resumable session's claim -- a completion keeping its file
     * (ADR 0143) -- holds, in seconds: fifteen minutes, well above the
     * longest keep, a file of the largest size fetched back from the store,
     * drawn again and scanned. A claim older than this was left by a
     * process that died holding it: the session may be claimed again, read
     * as open, aborted, and is pruned. An action's claim on an upload of a
     * type is never taken back.
     */
    public const int SESSION_CLAIM_LEASE = 900;

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
     * Marks an upload claimed, if it is unclaimed -- or a resumable session
     * (of no type) whose claim is SESSION_CLAIM_LEASE old or older -- and
     * has not expired. Atomic: of two calls claiming one upload, one is told
     * true.
     */
    public function claim(string $id, int $now): bool;

    /**
     * Unclaims an upload, for an action whose body failed after claiming it
     * -- or, given $claimedAt, only if its claim is still the one made then:
     * a resumable session's claim is fenced by the time it was made, and one
     * taken over since (SESSION_CLAIM_LEASE) is another request's to give
     * back, so nothing is done.
     */
    public function release(string $id, ?int $claimedAt = null): void;

    /**
     * Moves a resumable session's claim made at $claimedAt to $now, if it is
     * still that claim: a keep renews its lease as it goes. False when the
     * claim was taken over, or given up.
     */
    public function renew(string $id, int $claimedAt, int $now): bool;

    /**
     * Unclaimed uploads that have expired, oldest first -- and resumable
     * sessions (of no type) past their expiry, claimed or not, or claimed
     * SESSION_CLAIM_LEASE before $now or longer: a claim held past either
     * was left by a process that died holding it.
     *
     * @return list<PendingUpload>
     */
    public function expired(int $now, int $limit): array;

    /**
     * Forgets an upload entirely -- or, given $claimedAt, only if it is
     * claimed by the claim made then (release()). Whether it was forgotten.
     */
    public function forget(string $id, ?int $claimedAt = null): bool;

    /** Forgets every claim made before $before; returns how many. Their files are not touched. */
    public function forgetClaimedBefore(int $before): int;
}
