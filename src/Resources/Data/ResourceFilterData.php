<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/** One filter above a resource's list, and the value it has now, null for none. */
final readonly class ResourceFilterData extends Data
{
    /** @param list<ResourceOptionData> $options */
    public function __construct(
        public string $key,
        public string $label,
        public array $options,
        public ?string $value,
    ) {}
}
