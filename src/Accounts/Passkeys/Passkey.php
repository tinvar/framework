<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

use Carbon\CarbonImmutable;

/** One passkey an account keeps, as PasskeyStore reads it back. */
final readonly class Passkey
{
    /**
     * @param string $id the store's own id for it, which a removal names: not the credential's id
     * @param string $credentialId the authenticator's id for it, raw bytes
     * @param string $userHandle the account's handle every one of its passkeys carries, raw bytes
     * @param string $publicKey the credential's COSE key, raw bytes
     * @param list<string> $transports what the browser said it can be reached over
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $credentialId,
        public string $userHandle,
        public string $publicKey,
        public int $signCount,
        public string $name,
        public array $transports,
        public bool $backedUp,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}
}
