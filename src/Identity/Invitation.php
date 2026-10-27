<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * An invite to join a panel in a tenant with a role, as Invitations reads
 * it back from its link's record -- never from the token, nor from what the
 * person accepting it posts.
 */
final readonly class Invitation
{
    /**
     * @param string $id the link's id, which a revocation names
     * @param string $email the address invited, trimmed and lowercased
     * @param string $inviter the id of the account that sent it
     * @param int $expiresAt seconds since the epoch
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $tenant,
        public string $panel,
        public string $role,
        public string $inviter,
        public int $createdAt,
        public int $expiresAt,
    ) {}

    /** The invitation a link's record is, or null for one that is not an invite's. */
    public static function of(EmailLink $link): ?self
    {
        $email = $link->data['email'] ?? '';
        $panel = $link->data['panel'] ?? null;
        $role = $link->data['role'] ?? '';
        $inviter = $link->data['inviter'] ?? '';
        if ($link->purpose !== EmailLinks::INVITE || $email === '' || $panel === null || $role === '' || $inviter === '') {
            return null;
        }

        return new self($link->id, $email, $link->tenant, $panel, $role, $inviter, $link->createdAt, $link->expiresAt);
    }
}
