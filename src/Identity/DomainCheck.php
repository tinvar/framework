<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** One look at where a domain's DNS points, and why it was read that way. */
final readonly class DomainCheck
{
    /** @param string $reason what DNS said, in the platform's words: a deactivated domain's reason */
    public function __construct(
        public DomainPointing $pointing,
        public string $reason,
    ) {}
}
