<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;

/**
 * A handoff that has proven itself: who arrives, at the auth epoch the code
 * was minted on (still theirs), in which realm, and where they were going.
 * What the application starts a new session from (HandoffSessions).
 */
final readonly class Arrival
{
    public function __construct(
        public Authenticatable $user,
        public string $epoch,
        public Realm $realm,
        public string $returnTo,
    ) {}
}
