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

    /**
     * Every middleware an entry point declares, the class's first, then its
     * method's, each once.
     *
     * The attribute targets methods, and an #[Middleware] on a page's
     * `__invoke` compiled into nothing: the route ran without it.
     *
     * @param class-string $class
     * @return list<class-string<\Tinvar\Http\Middleware>>
     */
    public static function declaredOn(string $class, ?string $method = null): array
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(self::class);

        if ($method !== null && $reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(self::class)];
        }

        $out = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->newInstance()->classes as $middleware) {
                if (!\in_array($middleware, $out, true)) {
                    $out[] = $middleware;
                }
            }
        }

        return $out;
    }
}
