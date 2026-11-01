<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/**
 * One row of a resource's list. Each cell is text, keyed by its column:
 * a number as its digits, an enum as its case's value, a moment as ISO 8601
 * in UTC, a bool as `true` or `false`, a record it belongs to as that
 * record's label; null for none. The column says how to draw it.
 */
final readonly class ResourceRowData extends Data
{
    /**
     * @param array<string, string|null> $cells column key => value
     * @param string|null $editUrl the edit form, where the reader's policy lets them change
     *        this row; $canDelete, whether it lets them delete it. Each decides only what
     *        is drawn: the page and the action ask the policy again
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $cells,
        public string $showUrl,
        public ?string $editUrl,
        public bool $canDelete,
    ) {}
}
