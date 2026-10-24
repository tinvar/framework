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
 * for.
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
    ) {}

    /** The same upload, claimed at $at -- or unclaimed, for null. */
    public function withClaimedAt(?int $at): self
    {
        return clone($this, ['claimedAt' => $at]);
    }

    /** What an action is handed for it. */
    public function upload(): Upload
    {
        return new Upload($this->id, $this->key, $this->name, $this->type, $this->size);
    }
}
