<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * Where a tenant API token stands, as a list of the tenant's tokens shows
 * it (ADR 0193): only an Active one is taken.
 */
enum TenantApiTokenStatus: string
{
    case Active = 'active';

    /** Past the expiry it was issued with. */
    case Expired = 'expired';

    /** Revoked by a member who may manage the tenant's tokens. */
    case Revoked = 'revoked';

    /**
     * The tenant's token epoch has moved since it was issued: every token
     * the tenant held was ended at once (TenantApiTokens::endAll()) -- an
     * owner left, a token was feared leaked and nobody knew which.
     */
    case Ended = 'ended';

    /** What a member reading the list is told of one. */
    public function describe(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::Ended => 'Ended with every token of the organization',
        };
    }
}
