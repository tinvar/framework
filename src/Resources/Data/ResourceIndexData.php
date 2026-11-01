<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;
use Tinvar\Orm\Direction;

/**
 * A resource's list: one page of its rows, in the order and under the
 * filters and search the URL asked for, as far as the resource allows
 * them -- a sort by a column that is not sortable, or a filter value that
 * is not offered, is no sort or no filter.
 */
final readonly class ResourceIndexData extends Data
{
    /**
     * @param list<ResourceColumnData> $columns
     * @param list<ResourceRowData> $rows
     * @param list<ResourceFilterData> $filters
     * @param list<ResourceBulkActionData> $bulkActions
     * @param bool $searchable whether the list has a search box; $search, what it holds
     */
    public function __construct(
        public ResourceSummaryData $resource,
        public array $columns,
        public array $rows,
        public array $filters,
        public bool $searchable,
        public string $search,
        public ?string $sort,
        public Direction $direction,
        public int $page,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public array $bulkActions,
        public ResourceActionsData $actions,
    ) {}
}
