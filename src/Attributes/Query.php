<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** A query callable from a server component over the request's own socket. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Query
{
    /**
     * @param string|null   $name  wire name; defaults to a dotted form of the class name
     * @param string|null   $cache TTL such as '5m'
     * @param \Closure|null $tags  cache tags, receiving the query's arguments
     * @param array<string, string> $args per-call argument types -- a model class, or a scalar type
     *        name -- which a batch query must declare, having no signature to read them from
     */
    public function __construct(
        public ?string $name = null,
        public ?string $cache = null,
        public ?\Closure $tags = null,
        public array $args = [],
        public bool $shared = false,
    ) {}

    /**
     * The method a query's caller actually reaches: loadMany() for a
     * BatchQuery, __invoke() otherwise.
     *
     * Named once, so the build rules and the registry read declarations off
     * the same method. A BatchQuery that also had an __invoke was checked on
     * __invoke, which the runner never calls, while loadMany() -- which it
     * does call -- went unexamined.
     *
     * @param class-string $class
     */
    public static function entryMethodOf(string $class): string
    {
        return is_subclass_of($class, \Tinvar\Query\BatchQuery::class) ? 'loadMany' : '__invoke';
    }
}
