<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/**
 * A titled group of lines under a record's own, which the resource adds
 * (Resource::sections()): its activity, what it is part of, anything the
 * application keeps about it.
 */
final readonly class ResourceSectionData extends Data
{
    /** @param list<ResourceDetailData> $details */
    public function __construct(
        public string $title,
        public array $details,
    ) {}
}
