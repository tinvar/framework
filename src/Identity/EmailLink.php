<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * One emailed link's record, as EmailLinks keeps it: what it is for, whose
 * it is, where it opens, and whether it has been used.
 *
 * The token itself is never kept. A link is found by a hash of the secret
 * its token carries, so a copy of the table is no list of links that sign
 * anyone in.
 */
final readonly class EmailLink
{
    /**
     * @param string $id a random id, which the link's own pages and a revocation name it by;
     *        not the secret, and nothing it can be opened with
     * @param string $purpose what it does, one of EmailLinks' purposes
     * @param string $tenant the tenant it was made in, '' for none
     * @param string $origin the https origin it was built on, and the only one it opens on
     * @param string $user whom it signs in, or whose password it resets; '' for an invite,
     *        which names an address, not an account
     * @param array<string, string> $data what the application stored with it -- an
     *        invite's address, panel, role and inviter -- read back from the store, never
     *        from the token
     * @param int $createdAt seconds since the epoch
     * @param int $expiresAt seconds since the epoch: it opens until then, and not at it
     * @param int|null $usedAt when it was used, or null
     * @param int|null $revokedAt when it was revoked, or null
     */
    public function __construct(
        public string $id,
        public string $purpose,
        public string $tenant,
        public string $origin,
        public string $user,
        public array $data,
        public int $createdAt,
        public int $expiresAt,
        public ?int $usedAt = null,
        public ?int $revokedAt = null,
    ) {}

    /** Whether it could still be used at $now: not used, not revoked, not expired. */
    public function isOpen(int $now): bool
    {
        return $this->usedAt === null && $this->revokedAt === null && $this->expiresAt > $now;
    }
}
