<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** Adds only what the PHP type cannot express: a different column name, or a storage type. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
    ) {}
}
