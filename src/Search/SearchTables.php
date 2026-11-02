<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Orm\Schema\Schema;

/**
 * The tables an in-database index lives in (ADR 0153), made by one line in
 * a migration of the application's:
 *
 *     public function up(Schema $schema): void
 *     {
 *         SearchTables::create($schema);
 *     }
 *
 * One index for every #[Searchable] model, keyed by the model's table and
 * the row's key, each document under the tenant it was written in. Its
 * shape names no field -- a document is the text of its model's four
 * weight classes -- so a model made searchable, or a field added, needs a
 * reindex and never another migration.
 *
 * - SQLite: `search_documents`, a plain table holding each document's
 *   model, key and tenant, indexed, and `search_documents_text`, an FTS5
 *   table of the four classes under the same rowid, tokenized with Porter's
 *   English stemmer over unicode61 with diacritics folded.
 * - Postgres: `search_documents`, one row per document with its tsvector,
 *   each class under its label, A to D, behind a GIN index; held by
 *   row-level security to the tenant in force -- or to no tenant, a model
 *   that is not #[TenantScoped] -- as a #[TenantScoped] table is
 *   (RowLevelSecurity), so a raw read of it that forgot the predicate
 *   still sees one tenant's documents.
 * - MySQL: refused, saying so. Its FULLTEXT index is not built; use
 *   Meilisearch there (TINVAR_SEARCH_BACKEND=meilisearch).
 */
final class SearchTables
{
    /** Each document's model, key and tenant; on Postgres, its text too. */
    public const string TABLE = 'search_documents';

    /** SQLite's FTS5 table of each document's text, by the rowid of its row in TABLE. */
    public const string TEXT_TABLE = 'search_documents_text';

    /** The text search configuration Postgres stems by, and SQLite's tokenizer is the match of. */
    public const string POSTGRES_CONFIGURATION = 'english';

    /** SQLite's FTS5 tokenizer: Porter's English stemmer, over unicode61 folding diacritics. */
    public const string SQLITE_TOKENIZER = 'porter unicode61 remove_diacritics 2';

    public static function create(Schema $schema): void
    {
        foreach (self::statements($schema->driver()) as $sql) {
            $schema->sql($sql);
        }
    }

    public static function drop(Schema $schema): void
    {
        if ($schema->driver() === 'sqlite') {
            $schema->sql('DROP TABLE IF EXISTS "' . self::TEXT_TABLE . '"');
        }
        $schema->sql('DROP TABLE IF EXISTS "' . self::TABLE . '"');
    }

    /**
     * What create() runs on $driver: every table made only where missing,
     * and the policy made again.
     *
     * @return list<string>
     */
    public static function statements(string $driver): array
    {
        $table = '"' . self::TABLE . '"';

        return match ($driver) {
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY, model TEXT NOT NULL, model_id TEXT NOT NULL, tenant_id TEXT NOT NULL DEFAULT '', UNIQUE (model, model_id))",
                'CREATE INDEX IF NOT EXISTS "search_documents_model_tenant" ON ' . $table . ' (model, tenant_id)',
                'CREATE VIRTUAL TABLE IF NOT EXISTS "' . self::TEXT_TABLE . "\" USING fts5(a, b, c, d, tokenize = '" . self::SQLITE_TOKENIZER . "')",
            ],
            'pgsql' => [
                "CREATE TABLE IF NOT EXISTS {$table} (model TEXT NOT NULL, model_id TEXT NOT NULL, tenant_id TEXT NOT NULL DEFAULT '', document TSVECTOR NOT NULL, PRIMARY KEY (model, model_id))",
                'CREATE INDEX IF NOT EXISTS "search_documents_model_tenant" ON ' . $table . ' (model, tenant_id)',
                'CREATE INDEX IF NOT EXISTS "search_documents_document" ON ' . $table . ' USING GIN (document)',
                ...self::policy(),
            ],
            default => throw new \InvalidArgumentException(
                "Search keeps no index in a {$driver} database: set TINVAR_SEARCH_BACKEND=meilisearch and keep it in Meilisearch, or use SQLite or Postgres.",
            ),
        };
    }

    /**
     * Postgres's policy on the index: RowLevelSecurity's predicate --
     * acrossTenants(), the tenant in force, or the subtree widened to --
     * and one more branch, no tenant: the documents of a model that is not
     * #[TenantScoped], which are every reader's, as its rows are. Search
     * never writes one for a #[TenantScoped] model: it stamps the tenant
     * the row was read in.
     *
     * @return list<string>
     */
    public static function policy(): array
    {
        $predicate = \sprintf(
            "tenant_id = '' OR current_setting('%s', true) = 'on' OR tenant_id = NULLIF(current_setting('%s', true), '')"
            . " OR tenant_id = ANY (NULLIF(current_setting('%s', true), '')::text[])",
            RowLevelSecurity::ACROSS_SETTING,
            RowLevelSecurity::TENANT_SETTING,
            RowLevelSecurity::SUBTREE_SETTING,
        );
        $table = '"' . self::TABLE . '"';

        return [
            "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$table} FORCE ROW LEVEL SECURITY",
            'DROP POLICY IF EXISTS ' . RowLevelSecurity::POLICY . " ON {$table}",
            'CREATE POLICY ' . RowLevelSecurity::POLICY . " ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})",
        ];
    }
}
