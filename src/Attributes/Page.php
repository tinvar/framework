<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** A route. There are no route files: the class with this attribute is the route. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Page
{
    public function __construct(
        public string $path,
        public string $view,
        public ?string $name = null,
    ) {}
}
