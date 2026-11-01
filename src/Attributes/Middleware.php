<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Http\DeclaredMiddleware;

/**
 * Middleware for a page, layout, action or query.
 *
 * Repeatable, and applied after any global stack. Middleware is PHP only: the
 * renderer never runs it and never sees a client request. A query's runs on
 * an HTTP call to /_query; a render's request context is its page's chain.
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
     * Every middleware an entry point declares, the class's first -- its
     * ancestors' and traits' included, see ClassAttributes -- then its
     * method's, each once.
     *
     * The attribute targets methods, and an #[Middleware] on a page's
     * `__invoke` compiled into nothing: the route ran without it.
     *
     * Each level -- the class, then the method -- also lists the middleware
     * its MiddlewareAttributes declare (a package's `#[RequiresPlan]`), after
     * its #[Middleware], as references DeclaredMiddleware builds (ADR 0150).
     *
     * @param class-string $class
     * @return list<class-string<\Tinvar\Http\Middleware>>
     */
    public static function declaredOn(string $class, ?string $method = null): array
    {
        $reflection = new \ReflectionClass($class);
        $levels = [[ClassAttributes::of($reflection, self::class), DeclaredMiddleware::on($reflection)]];
        if ($method !== null && $reflection->hasMethod($method)) {
            $levels[] = [$reflection->getMethod($method)->getAttributes(self::class), DeclaredMiddleware::on($reflection, $method)];
        }

        $out = [];
        foreach ($levels as [$attributes, $references]) {
            $named = [];
            foreach ($attributes as $attribute) {
                array_push($named, ...$attribute->newInstance()->classes);
            }
            foreach ([...$named, ...$references] as $middleware) {
                if (!\in_array($middleware, $out, true)) {
                    $out[] = $middleware;
                }
            }
        }

        return $out;
    }
}
