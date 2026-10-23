<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/** The model a Data object maps from. Same-named properties map automatically. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class MapsFrom
{
    /** @param class-string $model */
    public function __construct(public string $model) {}
}
