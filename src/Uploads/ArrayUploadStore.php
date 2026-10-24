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

    public function find(string $id): ?PendingUpload
    {
        return $this->uploads[$id] ?? null;
    }

    public function pendingCount(string $authHash, int $now): int
    {
        return \count(array_filter(
            $this->uploads,
            static fn (PendingUpload $u): bool => $u->authHash === $authHash && $u->claimedAt === null && $u->expiresAt > $now,
        ));
    }

    public function claim(string $id, int $now): bool
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload === null || $upload->claimedAt !== null || $upload->expiresAt <= $now) {
            return false;
        }

        $this->uploads[$id] = $upload->withClaimedAt($now);

        return true;
    }

    public function release(string $id): void
    {
        $upload = $this->uploads[$id] ?? null;
        if ($upload !== null) {
            $this->uploads[$id] = $upload->withClaimedAt(null);
        }
    }

    public function expired(int $now, int $limit): array
    {
        $expired = array_values(array_filter(
            $this->uploads,
            static fn (PendingUpload $u): bool => $u->claimedAt === null && $u->expiresAt <= $now,
        ));
        usort($expired, static fn (PendingUpload $a, PendingUpload $b): int => $a->expiresAt <=> $b->expiresAt);

        return \array_slice($expired, 0, max(1, $limit));
    }

    public function forget(string $id): void
    {
        unset($this->uploads[$id]);
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
