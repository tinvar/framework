<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * An endpoint answering GET, and HEAD with it: `#[Endpoint($path)]`.
 *
 * Its body runs read-only, as a loader's does. A GET carries no CSRF token,
 * so a page on another site can make a visitor's browser send one, and
 * nothing it does may change anything.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Get
{
    public function __construct(
        public string $path,
        public ?string $name = null,
    ) {}
}
