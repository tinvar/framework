<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * The index in Postgres (ADR 0150): one row per document in
 * `search_documents`, its text a tsvector -- each weight class under its
 * label, A to D, stemmed by the `english` configuration -- behind a GIN
 * index, and ranked by ts_rank with the model's own weights.
 *
 * Every read and write also passes the table's row-level security policy
 * (SearchTables::policy()), the tenant told on the connection as for any
 * #[TenantScoped] table: what forgot its predicate here still reads one
 * tenant's documents. A key held under another tenant is never moved: the
 * upsert updates a document only in the tenant it was written in.
 */
final class PostgresSearch extends DatabaseSearch
{
    private const string DOCUMENTS = '"' . SearchTables::TABLE . '"';

    private const string CONFIGURATION = "'" . SearchTables::POSTGRES_CONFIGURATION . "'";

    public function upsert(SearchableModel $model, string $tenant, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $vector = implode(' || ', array_map(
            static fn (string $label): string => 'setweight(to_tsvector(' . self::CONFIGURATION . ', ?), \'' . strtoupper($label) . '\')',
            SearchableModel::CLASSES,
        ));
        $this->guarded(fn () => $this->connection->transaction(function () use ($model, $tenant, $documents, $vector): void {
            foreach ($documents as $document) {
                $this->connection->execute(
                    'INSERT INTO ' . self::DOCUMENTS . " (model, model_id, tenant_id, document) VALUES (?, ?, ?, {$vector})"
                    . ' ON CONFLICT (model, model_id) DO UPDATE SET document = EXCLUDED.document'
                    . ' WHERE ' . self::DOCUMENTS . '.tenant_id = EXCLUDED.tenant_id',
                    [$model->table, $document->id, $tenant, ...$document->classes],
                );
            }
        }));
    }

    public function remove(SearchableModel $model, ?array $tenants, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        [$scope, $scopeBindings] = self::tenantPredicate($tenants);
        $this->guarded(function () use ($model, $ids, $scope, $scopeBindings): void {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $this->connection->execute(
                    'DELETE FROM ' . self::DOCUMENTS . ' WHERE model = ? AND model_id IN (' . self::placeholders($chunk) . ')' . $scope,
                    [$model->table, ...$chunk, ...$scopeBindings],
                );
            }
        });
    }

    public function search(SearchableModel $model, SearchTerms $terms, ?array $tenants, int $limit, int $offset): SearchHits
    {
        if ($terms->isEmpty()) {
            return SearchHits::none();
        }
        [$scope, $scopeBindings] = self::tenantPredicate($tenants);
        $query = 'to_tsquery(' . self::CONFIGURATION . ', ?)';
        $where = ' FROM ' . self::DOCUMENTS . " WHERE model = ?{$scope} AND document @@ {$query}";
        $bindings = [$model->table, ...$scopeBindings, $terms->tsquery()];

        // ts_rank() takes its weights as {D, C, B, A}, each at most 1: the
        // model's, over the highest of them: A's.
        $highest = ($model->weights[0] ?? 0.0) > 0 ? $model->weights[0] : 1.0;
        $weights = array_map(static fn (float $w): float => $w / $highest, array_reverse($model->weights));

        return $this->guarded(function () use ($where, $bindings, $query, $weights, $terms, $limit, $offset): SearchHits {
            $total = (int) ($this->connection->select('SELECT count(*) AS total' . $where, $bindings)[0]['total'] ?? 0);
            if ($total === 0 || $offset >= $total) {
                return new SearchHits([], $total);
            }
            $rows = $this->connection->select(
                'SELECT model_id AS id' . $where
                . " ORDER BY ts_rank(ARRAY[CAST(? AS real), CAST(? AS real), CAST(? AS real), CAST(? AS real)], document, {$query}) DESC, model_id"
                . ' LIMIT ? OFFSET ?',
                [...$bindings, ...$weights, $terms->tsquery(), $limit, $offset],
            );

            return new SearchHits(array_map(static fn (array $row): string => (string) $row['id'], $rows), $total);
        });
    }

    public function flush(SearchableModel $model, ?string $tenant): void
    {
        [$scope, $scopeBindings] = self::tenantPredicate($tenant === null ? null : [$tenant]);
        $this->guarded(fn () => $this->connection->execute('DELETE FROM ' . self::DOCUMENTS . " WHERE model = ?{$scope}", [$model->table, ...$scopeBindings]));
    }
}
