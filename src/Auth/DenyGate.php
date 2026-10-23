<?php

declare(strict_types=1);

namespace Tinvar\Auth;

use Tinvar\Attributes\Authorize;

/** Denies every check. Used in tests to prove authorization runs before the body. */
final class DenyGate implements Gate
{
    public function allows(Authorize $check, array $arguments): bool
    {
        return false;
    }
}
