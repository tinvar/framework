<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * One tenant API token as it is kept (ADR 0193): everything about it but
 * its secret, of which only a digest is stored, and never here
 * (TenantApiTokenStore).
 *
 * Owned by its tenant, not by the member who made it: bound to the tenant,
 * the panel it was issued in, the host the request was on where the
 * application routes by host (ADR 0149), and the tenant's token epoch when
 * it was issued -- each checked again at every use, so a token is good in
 * its own tenant and panel, on its own host, until the tenant's tokens are
 * ended together (TenantApiTokens::endAll()). Who made it is recorded, and
 * binds it to nothing: a member who leaves takes no token with them.
 */
final readonly class TenantApiToken
{
    /**
     * @param string $id 32 lowercase hex digits, the middle of the token's text
     * @param string $name what it was called: what it is for
     * @param string $tenant the tenant it belongs to; never ''
     * @param string $panel the panel it was issued in; '' for none
     * @param string $host the host it was issued on (CallHosts::issuing()); '' where the
     *        application routes on no host
     * @param list<string> $scopes what it may be used for, sorted, each once
     * @param string $epoch the tenant's token epoch when it was issued
     * @param string $createdBy the id of the member who made it
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $tenant,
        public string $panel,
        public string $host,
        public array $scopes,
        public string $epoch,
        public string $createdBy,
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
     * Where it stands at $now for a tenant now on token epoch $epoch:
     * revoked first, then ended with the rest of its tenant's, then expired.
     */
    public function statusAt(CarbonImmutable $now, string $epoch): TenantApiTokenStatus
    {
        return match (true) {
            $this->revokedAt !== null => TenantApiTokenStatus::Revoked,
            !hash_equals($this->epoch, $epoch) => TenantApiTokenStatus::Ended,
            $this->expiresAt !== null && $this->expiresAt->lessThanOrEqualTo($now) => TenantApiTokenStatus::Expired,
            default => TenantApiTokenStatus::Active,
        };
    }
}
