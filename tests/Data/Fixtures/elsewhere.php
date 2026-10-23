<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\Elsewhere;

use Tinvar\Data\Data;

final readonly class ItemData extends Data
{
    public function __construct(public string $id) {}
}
