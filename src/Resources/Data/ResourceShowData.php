<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/** One record of a resource: its lines, the sections the resource adds under them, and what the reader may do to it. */
final readonly class ResourceShowData extends Data
{
    /**
     * @param list<ResourceDetailData> $details
     * @param list<ResourceSectionData> $sections
     * @param string|null $editUrl see ResourceRowData
     */
    public function __construct(
        public ResourceSummaryData $resource,
        public string $id,
        public string $title,
        public array $details,
        public array $sections,
        public ?string $editUrl,
        public bool $canDelete,
        public ResourceActionsData $actions,
    ) {}
}
