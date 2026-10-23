<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Middleware for a page, layout or action.
 *
 * Repeatable, and applied after any global stack. Middleware is PHP only: the
 * renderer never runs it and never sees a client request.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /** @var list<class-string<\Tinvar\Http\Middleware>> */
    public array $classes;

    /** @param class-string<\Tinvar\Http\Middleware> ...$classes */
    public function __construct(string ...$classes)
    {
        $this->classes = array_values($classes);
    }
}
