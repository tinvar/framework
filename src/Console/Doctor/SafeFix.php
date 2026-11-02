<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor;

/**
 * What `tinvar doctor --fix` may do about a finding by itself: only what
 * cannot lose anything or open anything -- a dev secret generated where
 * there is none, the pending migrations run on a developer's machine
 * (ADR 0155). Everything else is said as a fix for a person to make.
 */
final readonly class SafeFix
{
    /**
     * @param string $describe what it does, `generate the app key into /home/you/.local/state/tinvar/app-2cab79174cf3.key`
     * @param \Closure(): string $apply does it, and says what it did; throws to say why it could not
     */
    public function __construct(
        public string $describe,
        public \Closure $apply,
    ) {}
}
