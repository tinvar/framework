<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Orm\Orm;
use Tinvar\Orm\TenantBounds;

/**
 * The tenant a signed token or ticket is bound to: what it names, and what
 * the request presenting it is held to.
 *
 * Route, fragment, live and upload tokens, handoff codes and consent tickets
 * all carry a tenant, and a mismatch refuses them (TokenClaims). Tenancy is
 * a package an application opts into, so the claim depends on whether the
 * Orm was given one (ADR 0056):
 *
 * - **With tenancy**, the claim is the tenant itself -- the tenant in force,
 *   or the one a realm names -- exactly as it always was, so every token
 *   minted before still verifies, and one bound to a tenant is refused in
 *   any other.
 * - **With none**, it is NONE, whatever tenant the Orm's context names: a
 *   value no tenant can be, since a tenant's name has no control character
 *   in it (TenantBounds::isName()). So a token minted in one mode never
 *   verifies in the other -- one minted where tenancy was off cannot be
 *   replayed once it is on, as the empty tenant could have been, and none
 *   minted with tenancy names a tenant an application without it could be
 *   talked into.
 *
 * Minting and verifying both come through here, so the two sides cannot
 * read the tenant from different places -- which TokenClaims records
 * happening more than once.
 */
final class TenantClaim
{
    /** The claim with no tenancy registered: DEL, then a word, so a log line says what it is. */
    public const string NONE = "\x7fno tenancy";

    /**
     * The claim for $tenant: the tenant with tenancy registered, NONE
     * without.
     *
     * @throws \LogicException for a tenant that is no tenant's name, which
     *         could otherwise be spelled to look like NONE
     */
    public static function of(Orm $orm, string $tenant): string
    {
        if (!$orm->hasTenancy()) {
            return self::NONE;
        }
        if ($tenant !== '' && !TenantBounds::isName($tenant)) {
            throw new \LogicException('A token is bound to a tenant, and this one has a control character in its name.');
        }

        return $tenant;
    }

    /** The claim for the tenant in force: what a token minted now names, and one presented now is held to. */
    public static function current(Orm $orm): string
    {
        return self::of($orm, $orm->tenant());
    }
}
