<?php

declare(strict_types=1);

namespace Tinvar\Api;

/**
 * Whose an API token is, as its text says by its prefix (ApiTokenSecret):
 * a user's personal token (ADR 0159), or a tenant's (ADR 0193). A token is
 * looked up in its own kind's store alone, so one kind is never taken for
 * the other.
 */
enum ApiTokenKind: string
{
    /** A user's personal access token: `tvr_...`, run as its user. */
    case User = 'tvr_';

    /** A tenant's token: `tvt_...`, run as the token itself (TenantTokenActor). */
    case Tenant = 'tvt_';

    /** The regular expression a secret scanner finds a token of this kind by. */
    public function scannerPattern(): string
    {
        return $this->value . '[0-9a-f]{32}_[0-9A-Za-z]{43}';
    }
}
