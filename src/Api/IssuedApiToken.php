<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * A token just issued, with its text: what its user is shown once, and
 * never again -- only the secret's digest is kept, so nothing can show it
 * later. Hand $plaintext to the person who asked for it, in the response to
 * their request, and keep it nowhere: not in a flash, a log or a job.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
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
