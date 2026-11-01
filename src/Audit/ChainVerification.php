<?php

declare(strict_types=1);

namespace Tinvar\Audit;

/**
 * What AuditLog::verify() found walking a tenant's log (ADR 0150): whether
 * every entry is where the chain says and as it was written, how many it
 * walked, and where the first break is.
 */
final readonly class ChainVerification
{
    /**
     * @param int $entries how many entries were walked and held
     * @param int|null $brokenAt the place of the first entry that does not hold, or the head's
     * @param string|null $problem what was wrong there, naming no entry's contents
     */
    public function __construct(
        public string $tenant,
        public bool $intact,
        public int $entries,
        public ?int $brokenAt = null,
        public ?string $problem = null,
    ) {}
}
