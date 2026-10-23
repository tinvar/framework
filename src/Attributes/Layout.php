<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** A nested layout, paired with a TSX layout and loaded outermost first. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Layout
{
    public function __construct(
        public string $path,
        public string $view,
    ) {}
}
