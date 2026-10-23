<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A declared access check. Authorization runs before anything else: before a
 * cache lookup, validation, batching or Precognition.
 *
 * Phase 0 records the declaration and runs it through a stub gate; the policy
 * engine is Phase 1. The attribute shape matters now because the security
 * model's build rules are written against it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Authorize
{
    /** @param string|null $argument name of the model-typed input this check covers */
    public function __construct(
        public string $ability,
        public ?string $argument = null,
    ) {}
}
