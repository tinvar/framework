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
     * @param array<string, class-string> $args per-call model argument types, declared by batch queries
     */
    public function __construct(
        public ?string $name = null,
        public ?string $cache = null,
        public ?\Closure $tags = null,
        public array $args = [],
        public bool $shared = false,
    ) {}
}
