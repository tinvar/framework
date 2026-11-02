<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * Where an API token stands, as a list of them shows it: only an Active
 * one signs anyone in.
 */
enum ApiTokenStatus: string
{
    case Active = 'active';

    /** Past the expiry it was issued with. */
    case Expired = 'expired';

    /** Revoked by its user, or by whatever revoked it for them. */
    case Revoked = 'revoked';

    /**
     * Its user's auth epoch has moved since it was issued -- a sign-out
     * everywhere, a password changed, a role taken away -- which ends every
     * token they held, as it ends every session (ADR 0158).
     */
    case SignedOut = 'signed_out';

    /** What a person reading a list of their tokens is told of one. */
    public function describe(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::SignedOut => 'Ended when you signed out everywhere or your access changed',
        };
    }
}
