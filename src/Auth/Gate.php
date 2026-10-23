<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Authorize;

/**
 * Authorization runs before anything else: before a cache lookup, validation,
 * batching or Precognition (tinvar-spec.md, "Security model").
 *
 * Phase 0 ships the seam and the ordering. The policy engine is Phase 1; the
 * brief asks for auth to be stubbed behind an interface.
 */
interface Gate
{
    /** @param array<string, mixed> $arguments */
    public function allows(Authorize $check, array $arguments): bool;
}
