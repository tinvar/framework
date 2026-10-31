<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * A stored file and everything it was uploaded under, waiting for the
 * action its ticket named to claim it.
 *
 * The binding is copied from the ticket that admitted the upload, so an
 * action call is held to the same things /_upload held the upload to: the
 * session and the user with their auth epoch (as keyed hashes), the tenant
 * and the panel, the action and its parameter, and the record the file is
 * for. Beside them, who uploaded it, whatever their epoch (Uploads::
 * ownerHash()), which the cap on a user's waiting uploads counts by.
 */
final readonly class PendingUpload
{
    public function __construct(
        public string $id,
        public string $key,
        public string $action,
        public string $field,
        public string $targetClass,
        public string $targetId,
        public string $prefix,
        public string $tenant,
        public string $panel,
        public string $sessionHash,
        public string $authHash,
        public string $name,
        public string $type,
        public int $size,
        public int $createdAt,
        public int $expiresAt,
        public ?int $claimedAt = null,
        public string $ownerHash = '',
    ) {}

    /** The same upload, claimed at $at -- or unclaimed, for null. */
    public function withClaimedAt(?int $at): self
    {
        return clone($this, ['claimedAt' => $at]);
    }

    /**
     * What is left of a direct upload's intent once completed: its key, to
     * be pruned $grace seconds after its URL runs out, and nothing else.
     *
     * A record of its own, under an ID no client was given, bound to no
     * session, user, action or record, of no type and no size, owned by no
     * one: so nothing completes it again or is handed it, and it counts
     * against no cap or quota -- the file kept from it is counted already.
     * Unclaimed, it is pruned as any expired upload is (Uploads::prune()):
     * its key removed, then the record.
     */
    public function consumed(int $now, int $grace): self
    {
        return new self(
            id: bin2hex(random_bytes(16)),
            key: $this->key,
            action: '',
            field: '',
            targetClass: '',
            targetId: '',
            prefix: '',
            tenant: '',
            panel: '',
            sessionHash: '',
            authHash: '',
            name: '',
            type: '',
            size: 0,
            createdAt: $now,
            expiresAt: $this->expiresAt + $grace,
        );
    }

    /** What an action is handed for it. */
    public function upload(): Upload
    {
        return new Upload($this->id, $this->key, $this->name, $this->type, $this->size);
    }
}
