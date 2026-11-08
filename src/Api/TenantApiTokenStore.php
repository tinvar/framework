<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * Where tenant API tokens are kept (ADR 0193): each one's record and the
 * SHA-256 of its secret, never the secret; and each tenant's token epoch,
 * which ends every token the tenant holds when it moves.
 *
 * Every statement about a tenant's tokens names the tenant: a token's id
 * from another tenant's form revokes nothing here. find() alone does not,
 * since a presented token is looked up before anything says which tenant
 * it is for -- its record says, and is held to the request's afterwards.
 */
interface TenantApiTokenStore
{
    /** Keeps a token just issued, with the digest of its secret. */
    public function insert(TenantApiToken $token, string $digest): void;

    /**
     * The token with $id and the digest of its secret, in any tenant; null
     * for none.
     *
     * @return array{TenantApiToken, string}|null
     */
    public function find(string $id): ?array;

    /**
     * Every token $tenant holds in $panel, revoked, ended and expired ones
     * too, newest first.
     *
     * @return list<TenantApiToken>
     */
    public function forTenant(string $tenant, string $panel): array;

    /** Revokes $tenant's token $id at $at; false for none of its, or one revoked already. */
    public function revoke(string $tenant, string $id, CarbonImmutable $at): bool;

    /**
     * Records that token $id was used at $at, unless it was recorded used
     * after $since already.
     */
    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void;

    /** $tenant's token epoch now; '0' before it ever moved. */
    public function epoch(string $tenant): string;

    /**
     * Moves $tenant's token epoch on, ending every token it holds: inside
     * the caller's transaction, where there is one -- the one that takes an
     * owner's membership away -- so the two are kept or lost together.
     */
    public function advance(string $tenant): void;
}
