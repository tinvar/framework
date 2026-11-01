<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;
use Tinvar\Resources\ColumnKind;

/** One line of a record's page: its label, its value as text (see ResourceRowData), and how to draw it. */
final readonly class ResourceDetailData extends Data
{
    /** @param list<ResourceOptionData> $options an enum's cases, to label the value with */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind,
        public ?string $value,
        public array $options = [],
    ) {}
}
