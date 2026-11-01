<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;
use Tinvar\Resources\FieldKind;

/**
 * One input of a resource's form. It posts as `values[<name>]`, and an
 * error about it comes back under `values.<name>`. Its value is what the
 * record holds, as text (see ResourceRowData; a record it belongs to as
 * that record's id), or null.
 */
final readonly class ResourceFormFieldData extends Data
{
    /** @param list<ResourceOptionData> $options a select's choices */
    public function __construct(
        public string $name,
        public string $label,
        public FieldKind $kind,
        public bool $required,
        public ?string $value,
        public array $options = [],
        public ?string $help = null,
        public ?float $min = null,
        public ?float $max = null,
        public bool $decimal = false,
    ) {}
}
