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

    /**
     * Revokes $tenant's token $id issued in $panel, at $at; false for none
     * of its there, or one revoked already. Held to the panel as the list
     * is: a member of one panel revokes nothing another panel's list shows.
     */
    public function revoke(string $tenant, string $panel, string $id, CarbonImmutable $at): bool;

    /**
     * Records that token $id was used at $at, unless it was recorded used
     * after $since already.
     */
    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void;

    /**
     * Runs $work holding $tenant's token issuing to itself: in a
     * transaction (a savepoint inside one open) that holds the tenant's
     * epoch row, made where there is none, until it commits -- so two
     * tokens issued at once are counted one after the other, and neither
     * passes the most a tenant may hold. advance() waits for it too.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function exclusively(string $tenant, \Closure $work): mixed;

    /** $tenant's token epoch now; '0' before it ever moved. */
    public function epoch(string $tenant): string;

    /**
     * Moves $tenant's token epoch on, ending every token it holds: inside
     * the caller's transaction, where there is one -- the one that takes an
     * owner's membership away -- so the two are kept or lost together.
     */
    public function advance(string $tenant): void;
}
