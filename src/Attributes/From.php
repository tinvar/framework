<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** Maps a Data property from a differently-named model property. */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class From
{
    public function __construct(public string $property) {}
}
