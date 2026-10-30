<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * An ID token IdTokenVerifier believed: its issuer and subject -- together
 * the one name for the person the provider vouches for (OpenID Connect
 * Core 1.0, 5.7) -- and the rest of its claims, as the provider wrote them.
 *
 * An email is a claim, not an identity: an application matching accounts
 * by it reads `email_verified` first, and still trusts only providers that
 * own the addresses they vouch for.
 */
final readonly class IdToken
{
    /** @param array<string, mixed> $claims */
    public function __construct(
        public string $issuer,
        public string $subject,
        public array $claims,
    ) {}

    /** The verified email, or null where the provider does not say it verified one. */
    public function verifiedEmail(): ?string
    {
        $email = $this->claims['email'] ?? null;

        return \is_string($email) && ($this->claims['email_verified'] ?? null) === true ? $email : null;
    }
}
