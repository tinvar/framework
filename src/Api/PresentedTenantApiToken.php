<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Tinvar\Auth\TenantTokenActor;

/**
 * A tenant token a request presented that holds -- its secret matched,
 * neither revoked nor expired, its tenant still there and on the token
 * epoch it was issued on, presented on its own host -- with the actor its
 * call runs as (ADR 0193). What it may do beyond that is held where it is
 * used: its scopes, tenant and panel, and whether what it calls accepts a
 * tenant's token at all (ApiCalls, HoldApiToken).
 */
final readonly class PresentedTenantApiToken
{
    public function __construct(
        public TenantApiToken $token,
        public TenantTokenActor $actor,
    ) {}

    /** The auth epoch its request runs on: the tenant's token epoch, which ends it when it moves. */
    public function epoch(): string
    {
        return 'tenant-token:' . $this->token->epoch;
    }
}
