<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * How many bytes of uploaded files one user may hold: every file of theirs
 * an action kept and nothing has given up yet (Uploads::discard()), and
 * every one waiting to be claimed.
 *
 *     UploadQuota::perUser(50 * 1024 * 1024)
 *     UploadQuota::by(fn (string $user): ?int => $plans->storageFor($user))
 *
 * Counted by the user, whatever their auth epoch, as the cap on waiting
 * uploads is, and checked with the upload's own record as one write
 * (UploadStore::addWithin()), so files sent at once cannot each find room.
 * With a quota, a claimed upload's record is kept for as long as its file
 * is, not forgotten after a day: it is what the quota counts. So a file an
 * application removes is removed by Uploads::discard(), which forgets the
 * record with it and gives the bytes back; one deleted from storage behind
 * its back still counts against its owner.
 */
final readonly class UploadQuota
{
    /** @param \Closure(string): ?int $bytesFor the bytes a user may hold; null for no limit */
    private function __construct(private \Closure $bytesFor) {}

    public static function perUser(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('A quota is at least a byte.');
        }

        return new self(static fn (string $user): int => $bytes);
    }

    /** @param \Closure(string): ?int $bytesFor each user's quota, by their id; null for none */
    public static function by(\Closure $bytesFor): self
    {
        return new self($bytesFor);
    }

    /** The bytes $user may hold, or null for no limit. */
    public function bytesFor(string $user): ?int
    {
        $bytes = ($this->bytesFor)($user);

        return $bytes === null ? null : max(0, $bytes);
    }
}
