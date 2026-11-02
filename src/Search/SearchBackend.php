<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * Where an application's search index lives (ADR 0153): its own database
 * -- SQLite's FTS5 (SqliteSearch) or Postgres's tsvector (PostgresSearch),
 * the default -- or Meilisearch over the outbound client
 * (MeilisearchSearch), or a test's SearchFake.
 *
 * Every call names the tenants it is for, read by Search from the ORM's
 * scope, never from a request: a document is written under one tenant and
 * a search reads the tenants it is given and no other. '' is no tenant,
 * for a model that is not #[TenantScoped].
 */
interface SearchBackend
{
    /**
     * Whether writes leave the process: Search queues them, after the
     * commit, rather than making them in the request that wrote the rows.
     */
    public function remote(): bool;

    /**
     * Adds documents to $tenant's index of $model, or replaces those there
     * by key.
     *
     * @param list<SearchDocument> $documents
     * @throws SearchFailed
     */
    public function upsert(SearchableModel $model, string $tenant, array $documents): void;

    /**
     * Takes rows out of the index by key: of the tenants named, or of every
     * tenant where null -- a delete made across tenants.
     *
     * @param list<string>|null $tenants
     * @param list<string> $ids
     * @throws SearchFailed
     */
    public function remove(SearchableModel $model, ?array $tenants, array $ids): void;

    /**
     * The keys of $model's rows matching $terms, best first: in the tenants
     * named, or in every tenant where null -- a search made inside
     * acrossTenants(). Never asked with no words.
     *
     * @param list<string>|null $tenants
     * @throws SearchFailed
     */
    public function search(SearchableModel $model, SearchTerms $terms, ?array $tenants, int $limit, int $offset): SearchHits;

    /**
     * Empties $tenant's index of $model -- every tenant's where null -- for
     * a reindex to fill again.
     *
     * @throws SearchFailed
     */
    public function flush(SearchableModel $model, ?string $tenant): void;
}
