<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/** One choice of a filter, a select field, or an enum column: the value posted or stored, and what it is called. */
final readonly class ResourceOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
