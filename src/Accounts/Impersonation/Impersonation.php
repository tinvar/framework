<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Carbon\CarbonImmutable;

/**
 * A session staff are acting as its user in (ADR 0194): which
 * impersonation it is, who is doing it, why, and until when. Read with the
 * session's row on every request (Sessions::open()), and only while it
 * still stands: unexpired, its user's auth epoch where it began, and the
 * impersonator's own session -- parked while they act as someone else --
 * still there to go back to.
 */
final readonly class Impersonation
{
    /**
     * @param string $id the impersonation's id, as its row and the audit log's entries name it
     * @param string $impersonator who is acting as the session's user
     * @param CarbonImmutable $until when it ends by itself
     * @param string $reason why, as the impersonator gave it when they started
     */
    public function __construct(
        public string $id,
        public string $impersonator,
        public CarbonImmutable $until,
        public string $reason,
    ) {}
}
