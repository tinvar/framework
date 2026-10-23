<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A method callable from the browser as a typed TypeScript stub.
 *
 * Actions take the place of 'use server' functions: the renderer never
 * receives a client payload, so a server function endpoint there would be the
 * class of bug behind React's CVE-2025-55182. The stub POSTs to PHP instead.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Action
{
    public function __construct(
        public ?string $name = null,
    ) {}
}
