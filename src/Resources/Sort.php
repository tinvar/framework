<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Tinvar\Orm\Direction;

/** The order a resource's list is in until a heading is clicked: Resource::sort(). */
final readonly class Sort
{
    public function __construct(public string $key, public Direction $direction = Direction::Asc) {}

    public static function asc(string $key): self
    {
        return new self($key, Direction::Asc);
    }

    public static function desc(string $key): self
    {
        return new self($key, Direction::Desc);
    }
}
