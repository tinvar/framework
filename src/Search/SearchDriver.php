<?php

declare(strict_types=1);

namespace Tinvar\Search;

/** The backends TINVAR_SEARCH_BACKEND names (ADR 0154). */
enum SearchDriver: string
{
    /** The application's own database: SQLite's FTS5, or Postgres's tsvector. */
    case Database = 'database';

    /** Meilisearch, over the outbound client, an index per tenant. */
    case Meilisearch = 'meilisearch';
}
