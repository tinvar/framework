<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * The index in SQLite's FTS5 (ADR 0153): each document's model, key and
 * tenant in `search_documents`, its text in `search_documents_text` under
 * the same rowid, ranked by bm25 with the model's four class weights.
 *
 * A document is found by its key through the plain table's unique index,
 * and its text by rowid, so a write never scans the FTS table. A key held
 * under another tenant already is refused by that index rather than moved.
 */
final class SqliteSearch extends DatabaseSearch
{
    private const string DOCUMENTS = '"' . SearchTables::TABLE . '"';

    private const string TEXT = '"' . SearchTables::TEXT_TABLE . '"';

    public function upsert(SearchableModel $model, string $tenant, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $this->guarded(fn () => $this->connection->transaction(function () use ($model, $tenant, $documents): void {
            foreach ($documents as $document) {
                $found = $this->connection->select(
                    'SELECT id FROM ' . self::DOCUMENTS . ' WHERE model = ? AND model_id = ? AND tenant_id = ?',
                    [$model->table, $document->id, $tenant],
                );
                if ($found === []) {
                    $this->connection->execute(
                        'INSERT INTO ' . self::DOCUMENTS . ' (model, model_id, tenant_id) VALUES (?, ?, ?)',
                        [$model->table, $document->id, $tenant],
                    );
                    $found = $this->connection->select(
                        'SELECT id FROM ' . self::DOCUMENTS . ' WHERE model = ? AND model_id = ? AND tenant_id = ?',
                        [$model->table, $document->id, $tenant],
                    );
                } else {
                    $this->connection->execute('DELETE FROM ' . self::TEXT . ' WHERE rowid = ?', [$found[0]['id']]);
                }
                $this->connection->execute(
                    'INSERT INTO ' . self::TEXT . ' (rowid, a, b, c, d) VALUES (?, ?, ?, ?, ?)',
                    [$found[0]['id'], ...$document->classes],
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
        $this->guarded(fn () => $this->connection->transaction(function () use ($model, $ids, $scope, $scopeBindings): void {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $where = 'model = ? AND model_id IN (' . self::placeholders($chunk) . ')' . $scope;
                $bindings = [$model->table, ...$chunk, ...$scopeBindings];
                $this->connection->execute('DELETE FROM ' . self::TEXT . ' WHERE rowid IN (SELECT id FROM ' . self::DOCUMENTS . " WHERE {$where})", $bindings);
                $this->connection->execute('DELETE FROM ' . self::DOCUMENTS . " WHERE {$where}", $bindings);
            }
        }));
    }

    public function search(SearchableModel $model, SearchTerms $terms, ?array $tenants, int $limit, int $offset): SearchHits
    {
        if ($terms->isEmpty()) {
            return SearchHits::none();
        }
        [$scope, $scopeBindings] = self::tenantPredicate($tenants, 'd.tenant_id');
        $from = ' FROM ' . self::TEXT . ' JOIN ' . self::DOCUMENTS . ' AS d ON d.id = ' . self::TEXT . '.rowid'
            . ' WHERE ' . self::TEXT . ' MATCH ? AND d.model = ?' . $scope;
        $bindings = [$terms->fts5(), $model->table, ...$scopeBindings];

        return $this->guarded(function () use ($from, $bindings, $model, $limit, $offset): SearchHits {
            $total = (int) ($this->connection->select('SELECT count(*) AS total' . $from, $bindings)[0]['total'] ?? 0);
            if ($total === 0 || $offset >= $total) {
                return new SearchHits([], $total);
            }
            // bm25() is lower for a better match; each class weighed as the
            // model weighs it, bound as values.
            $rows = $this->connection->select(
                'SELECT d.model_id AS id' . $from . ' ORDER BY bm25(' . self::TEXT . ', ?, ?, ?, ?), d.model_id LIMIT ? OFFSET ?',
                [...$bindings, ...$model->weights, $limit, $offset],
            );

            return new SearchHits(array_map(static fn (array $row): string => (string) $row['id'], $rows), $total);
        });
    }

    public function flush(SearchableModel $model, ?string $tenant): void
    {
        [$scope, $scopeBindings] = self::tenantPredicate($tenant === null ? null : [$tenant]);
        $this->guarded(fn () => $this->connection->transaction(function () use ($model, $scope, $scopeBindings): void {
            $where = 'model = ?' . $scope;
            $bindings = [$model->table, ...$scopeBindings];
            $this->connection->execute('DELETE FROM ' . self::TEXT . ' WHERE rowid IN (SELECT id FROM ' . self::DOCUMENTS . " WHERE {$where})", $bindings);
            $this->connection->execute('DELETE FROM ' . self::DOCUMENTS . " WHERE {$where}", $bindings);
        }));
    }
}
