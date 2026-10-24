<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\Request;

/**
 * Something a request's tenant can be read from: what #[TenantFrom] names.
 *
 * Answers null when the request does not say -- an unknown host, a link
 * whose signature does not verify -- and never guesses. The caller refuses a
 * null answer; a source has no business choosing a fallback tenant, since
 * any fallback is some tenant's data served to a request that did not ask
 * for it.
 */
interface TenantSource
{
    /** The tenant the request is for, or null when this source cannot place it. */
    public function tenantFor(Request $request): ?string;
}
