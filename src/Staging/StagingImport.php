<?php

declare(strict_types=1);

namespace Tinvar\Staging;

use Tinvar\Support\Encrypter;

/**
 * Production's tenant data into staging, scrubbed and re-encrypted with
 * staging's keys, its credential, session and auth tables dropped
 * (tinvar-spec.md, "Dev and test tooling"; ADR 0090).
 *
 * Run from production's side: it reads production with production's own
 * key, which never leaves it, and writes staging's database with values
 * sealed under staging's. Staging's key is the one thing it is handed.
 *
 * Fail closed, and all or nothing:
 *
 *   - every table production has is in the plan -- copied, dropped or
 *     ignored -- and every column of a copied table has a rule, and every
 *     rule a column; staging has every table and column it writes;
 *   - in an import of one tenant, every copied table says which column is
 *     the tenant's, or that it is shared;
 *   - the two keys are not one: a staging encrypter with production's id
 *     is production's key, and the import stops;
 *   - all of that is checked before a row is read, and the rows are
 *     written in one transaction on staging, so a value that will not
 *     open, or a write that fails, leaves staging as it was.
 *
 * Staging's dropped tables are emptied, not left: a session staging made
 * before the import named a user id that is, after it, someone else.
 */
final readonly class StagingImport
{
    public function __construct(
        private ScrubPlan $plan,
        private Encrypter $production,
        private Encrypter $staging,
    ) {
        if ($production->keyId() === $staging->keyId()) {
            throw new ImportRefused(
                'Staging\'s key is production\'s. Staging encrypts under a key of its own -- one TINVAR_APP_KEY bound '
                . 'to the staging profile -- and production\'s never reaches it.',
            );
        }
    }

    /**
     * @param string|null $tenant the one tenant whose rows are copied; null for every row
     * @return array<string, int> each table written, with the rows written to it; a dropped one with 0
     * @throws ImportRefused before anything is written, or after, with everything rolled back
     */
    public function run(\PDO $production, \PDO $staging, ?string $tenant = null): array
    {
        $production->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $staging->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $from = new Schema($production);
        $to = new Schema($staging);
        if ($production === $staging || ($from->file() !== null && $from->file() === $to->file())) {
            throw new ImportRefused('Production and staging are one database here; the import writes only into staging\'s.');
        }
        $this->check($from, $to, $tenant);

        $context = new ScrubContext($this->production, $this->staging);
        $written = [];
        $to->begin();
        try {
            foreach ($this->plan->dropped() as $table) {
                if ($to->has($table)) {
                    $staging->exec('DELETE FROM ' . $to->quote($table));
                }
                $written[$table] = 0;
            }

            // Emptied newest first, so a row is gone before the row it names.
            $tables = $this->plan->tables();
            foreach (array_reverse(array_keys($tables)) as $table) {
                $column = $tenant === null || $tables[$table]['shared'] ? null : $tables[$table]['tenant'];
                if ($column === null) {
                    $staging->exec('DELETE FROM ' . $to->quote($table));
                } else {
                    $staging->prepare('DELETE FROM ' . $to->quote($table) . ' WHERE ' . $to->quote($column) . ' = ?')->execute([$tenant]);
                }
            }

            foreach ($tables as $table => $declared) {
                $written[$table] = $this->copy($production, $staging, $from, $to, $table, $declared, $tenant, $context);
            }

            $to->commit();
        } catch (\Throwable $e) {
            $to->rollBack();
            throw $e instanceof ImportRefused ? $e : new ImportRefused('The import failed, and staging is as it was: ' . $e->getMessage(), 0, $e);
        }

        return $written;
    }

    /** @throws ImportRefused */
    private function check(Schema $from, Schema $to, ?string $tenant): void
    {
        $problems = [];
        foreach ($from->tables() as $table) {
            if (!$this->plan->declares($table)) {
                $problems[] = "the table {$table} has no rule: copy it, drop it or ignore it";
            }
        }
        // And staging's: a table only staging has -- tokens, sessions, links
        // from an earlier staging life -- would go on working against the
        // users the import brings in. It is dropped (emptied) or ignored by
        // name, or the import stops.
        foreach ($to->tables() as $table) {
            if (!$from->has($table) && !$this->plan->declares($table)) {
                $problems[] = "staging's table {$table} has no rule: drop it, or ignore it if it holds no secret";
            }
        }

        foreach ($this->plan->tables() as $table => $declared) {
            if (!$from->has($table)) {
                $problems[] = "the plan copies {$table}, which production does not have";
                continue;
            }
            if (!$to->has($table)) {
                $problems[] = "staging has no table {$table}: migrate it first";
                continue;
            }

            $columns = $from->columns($table);
            foreach ($columns as $column) {
                if (!isset($declared['columns'][$column])) {
                    $problems[] = "{$table}.{$column} has no rule: scrub it, or keep() it if it is safe";
                }
            }
            $missing = array_diff(array_keys($declared['columns']), $columns);
            foreach ($missing as $column) {
                $problems[] = "the plan has a rule for {$table}.{$column}, which production does not have";
            }
            foreach (array_diff(array_keys($declared['columns']), $to->columns($table)) as $column) {
                $problems[] = "staging's {$table} has no column {$column}: migrate it first";
            }

            if ($tenant !== null && !$declared['shared'] && $declared['tenant'] === null) {
                $problems[] = "{$table} says no tenant column, and is not shared: an import of one tenant would copy every tenant's rows";
            }
        }

        // ScrubPlan refuses these already; held again here, where it counts.
        foreach ($this->plan->ignored() as $table) {
            if (ScrubPlan::credentialTable($table)) {
                $problems[] = "the plan ignores {$table}, which is named like a credential table: drop it";
            }
        }

        foreach ($this->plan->dropped() as $table) {
            if (!$from->has($table) && !$to->has($table)) {
                $problems[] = "the plan drops {$table}, which neither database has";
            }
        }

        if ($problems !== []) {
            throw new ImportRefused("The staging import is refused, and nothing was written:\n  - " . implode("\n  - ", $problems));
        }
    }

    /**
     * @param array{columns: array<string, Scrub>, tenant: ?string, shared: bool} $declared
     */
    private function copy(
        \PDO $production,
        \PDO $staging,
        Schema $from,
        Schema $to,
        string $table,
        array $declared,
        ?string $tenant,
        ScrubContext $context,
    ): int {
        $columns = array_keys($declared['columns']);
        $select = 'SELECT ' . implode(', ', array_map($from->quote(...), $columns)) . ' FROM ' . $from->quote($table);
        $filter = $tenant !== null && !$declared['shared'] ? $declared['tenant'] : null;
        $read = $production->prepare($filter === null ? $select : "{$select} WHERE " . $from->quote($filter) . ' = ?');
        $read->execute($filter === null ? [] : [$tenant]);

        $insert = $staging->prepare(
            'INSERT INTO ' . $to->quote($table) . ' (' . implode(', ', array_map($to->quote(...), $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, \count($columns), '?')) . ')',
        );

        $context->table = $table;
        $count = 0;
        while (($row = $read->fetch(\PDO::FETCH_ASSOC)) !== false) {
            /** @var array<string, mixed> $row */
            $context->row = $row;
            $values = [];
            foreach ($declared['columns'] as $column => $rule) {
                $context->column = $column;
                $values[] = $rule->apply($row[$column] ?? null, $context);
            }
            $insert->execute($values);
            $count++;
        }
        $context->row = [];

        return $count;
    }
}
