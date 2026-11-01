<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;
use Tinvar\Resources\FormMode;

/**
 * A resource's create or edit form: its fields, and the action it posts
 * to -- with, when editing, the record's id under `record`.
 */
final readonly class ResourceFormData extends Data
{
    /** @param list<ResourceFormFieldData> $fields */
    public function __construct(
        public ResourceSummaryData $resource,
        public FormMode $mode,
        public ?string $id,
        public string $title,
        public array $fields,
        public string $action,
        public string $cancelUrl,
    ) {}
}
