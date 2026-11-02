<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;

/**
 * Where API tokens are kept: each one's record and the SHA-256 of its
 * secret, never the secret (ADR 0155).
 *
 * Every statement about a user's tokens names the tenant: a token's id from
 * another tenant's form revokes nothing here. find() alone does not, since
 * a presented token is looked up before anything says which tenant it is
 * for -- its record says, and is held to the request's afterwards.
 */
interface ApiTokenStore
{
    /** Keeps a token just issued, with the digest of its secret. */
    public function insert(ApiToken $token, string $digest): void;

    /**
     * The token with $id and the digest of its secret, in any tenant; null
     * for none.
     *
     * @return array{ApiToken, string}|null
     */
    public function find(string $id): ?array;

    /**
     * Every token $user holds in $tenant and $panel, revoked and expired
     * ones too, newest first.
     *
     * @return list<ApiToken>
     */
    public function forUser(string $tenant, string $panel, string $user): array;

    /**
     * Revokes $user's token $id in $tenant, at $at; false when they hold no
     * such token, or it was revoked already.
     */
    public function revoke(string $tenant, string $user, string $id, CarbonImmutable $at): bool;

    /**
     * Records that token $id was used at $at, unless it was recorded used
     * after $since already: so a token called a hundred times a minute is
     * written to once.
     */
    public function touch(string $id, CarbonImmutable $at, CarbonImmutable $since): void;
}
