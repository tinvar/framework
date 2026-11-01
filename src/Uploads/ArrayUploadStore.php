<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/** Uploads in this process's memory: for tests, and nothing a second process can share. */
final class ArrayUploadStore implements UploadStore
{
    /** @var array<string, PendingUpload> */
    private array $uploads = [];

    public function add(PendingUpload $upload): void
    {
        if (isset($this->uploads[$upload->id])) {
            throw new \RuntimeException('An upload with this id is already stored.');
        }

        $this->uploads[$upload->id] = $upload;
    }

    public function addWithin(PendingUpload $upload, int $max, int $now, ?int $quotaBytes = null): bool
    {
        // One process's memory, so nothing runs between the count and the add.
        if ($this->pendingCount($upload->ownerHash, $now) >= $max
            || ($quotaBytes !== null && $this->bytesHeld($upload->ownerHash, $now) + $upload->size > $quotaBytes)) {
            return false;
        }
        $this->add($upload);

        return true;
    }

    public function find(string $id): ?PendingUpload
    {
        return $this->uploads[$id] ?? null;
    }

    public function pendingCount(string $ownerHash, int $now): int
    {
        return \count(array_filter(
            $this->uploads,
            static fn (PendingUpload $u): bool => $u->ownerHash === $ownerHash && $u->claimedAt === null,
        ));
    }

    public function bytesHeld(string $ownerHash, int $now): int
    {
        $held = 0;
        foreach ($this->uploads as $upload) {
            // Every record, until pruning or a discard forgets it: its
            // file is kept until then.
            if ($upload->ownerHash === $ownerHash) {
                $held += $upload->size;
            }
        }

        return $held;
    }

    public function forgetKey(string $key): void
    {
        foreach ($this->uploads as $id => $upload) {
            if ($upload->key === $key) {
                unset($this->uploads[$id]);
            }
        }
    }

    public function claim(string $id, int $now): bool
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload === null || $upload->expiresAt <= $now || ($upload->claimedAt !== null && !self::leaseRunOut($upload, $now))) {
            return false;
        }

        $this->uploads[$id] = $upload->withClaimedAt($now);

        return true;
    }

    public function release(string $id, ?int $claimedAt = null): void
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload !== null && ($claimedAt === null || $upload->claimedAt === $claimedAt)) {
            $this->uploads[$id] = $upload->withClaimedAt(null);
        }
    }

    public function renew(string $id, int $claimedAt, int $now): bool
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload === null || $upload->claimedAt !== $claimedAt) {
            return false;
        }
        $this->uploads[$id] = $upload->withClaimedAt($now);

        return true;
    }

    public function expired(int $now, int $limit): array
    {
        $expired = array_values(array_filter(
            $this->uploads,
            static fn (PendingUpload $u): bool => (($u->claimedAt === null || $u->type === '') && $u->expiresAt <= $now)
                || ($u->claimedAt !== null && self::leaseRunOut($u, $now)),
        ));
        usort($expired, static fn (PendingUpload $a, PendingUpload $b): int => $a->expiresAt <=> $b->expiresAt);

        return \array_slice($expired, 0, max(1, $limit));
    }

    /** Whether $upload is a resumable session whose claim has outlived its lease. */
    private static function leaseRunOut(PendingUpload $upload, int $now): bool
    {
        return $upload->type === '' && $upload->claimedAt !== null && $upload->claimedAt <= $now - self::SESSION_CLAIM_LEASE;
    }

    public function forget(string $id, ?int $claimedAt = null): bool
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload === null || ($claimedAt !== null && $upload->claimedAt !== $claimedAt)) {
            return false;
        }
        unset($this->uploads[$id]);

        return true;
    }

    public function forgetClaimedBefore(int $before): int
    {
        $forgotten = 0;
        foreach ($this->uploads as $id => $upload) {
            if ($upload->claimedAt !== null && $upload->claimedAt < $before) {
                unset($this->uploads[$id]);
                $forgotten++;
            }
        }

        return $forgotten;
    }

    /** @return list<PendingUpload> every upload held, for a test to look at */
    public function all(): array
    {
        return array_values($this->uploads);
    }
}
