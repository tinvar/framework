<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * One API token as it is kept: everything about it but its secret, of which
 * only a digest is stored, and never here (ApiTokenStore).
 *
 * A token is bound where it was issued (ADR 0155): to its user and the auth
 * epoch they were on, the tenant and the panel in force, and the host the
 * request was on where the application routes by host (ADR 0149) -- each
 * checked again at every use, so a token is good only for its user, while
 * their epoch stands, in its own tenant and panel, on its own host.
 */
final readonly class ApiToken
{
    /** The longest name a token may be given. */
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param string $id 32 lowercase hex digits, the middle of the token's text
     * @param string $name what its user called it: what it is for
     * @param string $user its user's id
     * @param string $epoch the auth epoch its user was on when it was issued
     * @param string $tenant the tenant it was issued in; '' for none
     * @param string $panel the panel it was issued in; '' for none
     * @param string $host the host it was issued on (CallHosts::issuing()); '' where the
     *        application routes on no host
     * @param list<string> $scopes what it may be used for, sorted, each once
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $user,
        public string $epoch,
        public string $tenant,
        public string $panel,
        public string $host,
        public array $scopes,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $lastUsedAt = null,
        public ?CarbonImmutable $revokedAt = null,
    ) {}

    /** The same token, revoked at $at. */
    public function withRevokedAt(CarbonImmutable $at): self
    {
        return clone($this, ['revokedAt' => $at]);
    }

    /** The same token, last used at $at. */
    public function withLastUsedAt(CarbonImmutable $at): self
    {
        return clone($this, ['lastUsedAt' => $at]);
    }

    /**
     * Whether it holds every scope in $required.
     *
     * @param list<string> $required
     */
    public function grants(array $required): bool
    {
        return array_diff($required, $this->scopes) === [];
    }

    /**
     * Where it stands at $now for a user now on $epoch: revoked first, then
     * ended by an epoch moved, then expired.
     */
    public function statusAt(CarbonImmutable $now, string $epoch): ApiTokenStatus
    {
        return match (true) {
            $this->revokedAt !== null => ApiTokenStatus::Revoked,
            !hash_equals($this->epoch, $epoch) => ApiTokenStatus::SignedOut,
            $this->expiresAt !== null && $this->expiresAt->lessThanOrEqualTo($now) => ApiTokenStatus::Expired,
            default => ApiTokenStatus::Active,
        };
    }
}
