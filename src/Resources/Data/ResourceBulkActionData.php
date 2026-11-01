<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/** One bulk action of a resource's list: the key it posts, what it is called, and whether to ask first. */
final readonly class ResourceBulkActionData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $destructive,
    ) {}
}
