<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Attributes\Config;

/**
 * Where the search index lives (ADR 0153): TINVAR_SEARCH_BACKEND, `database`
 * -- the application's own, SQLite's FTS5 or Postgres's tsvector, the
 * default -- or `meilisearch`, whose settings MeilisearchConfig reads.
 */
#[Config(prefix: 'TINVAR_SEARCH_')]
final readonly class SearchConfig
{
    public function __construct(
        public SearchDriver $backend = SearchDriver::Database,
    ) {}
}
