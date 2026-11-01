<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;
use Tinvar\Resources\ColumnKind;

/** One column of a resource's list, or one line of its record's page: what it is headed, and how its values are drawn. */
final readonly class ResourceColumnData extends Data
{
    /** @param list<ResourceOptionData> $options an enum column's cases, to label its values with */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind,
        public bool $sortable = false,
        public array $options = [],
    ) {}
}
