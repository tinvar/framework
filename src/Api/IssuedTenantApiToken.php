<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * A tenant token just issued, with its text: what the member who made it is
 * shown once, and never again -- only the secret's digest is kept. Hand
 * $plaintext to them in the response to their request, and keep it
 * nowhere: not in a flash, a log, an audit entry or a job.
 */
final readonly class IssuedTenantApiToken
{
    public function __construct(
        public TenantApiToken $token,
        #[\SensitiveParameter]
        public string $plaintext,
    ) {}

    /**
     * Never its text: a dump shows the token without it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'plaintext' => '[redacted]'];
    }
}
