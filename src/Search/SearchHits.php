<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * What a backend answers a search with (ADR 0152): the keys matched on one
 * page, best first, and how many matched in all -- Meilisearch's estimate
 * where it is the backend.
 *
 * Keys, never rows: SearchQuery reads them again through the ORM, under the
 * scope in force, so a key the index still holds for a row since deleted,
 * or one the reader may not see, is dropped there.
 */
final readonly class SearchHits
{
    /** @param list<string> $ids */
    public function __construct(
        public array $ids,
        public int $total,
    ) {}

    public static function none(): self
    {
        return new self([], 0);
    }
}
