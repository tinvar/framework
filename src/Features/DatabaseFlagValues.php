<?php

declare(strict_types=1);

namespace Tinvar\Features;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Flag values in a table of the application's database, so an operator can
 * switch a flag for a user, a tenant, a panel or everyone without a deploy,
 * and every worker reads the same row.
 *
 * One row per flag and part: `scope` is `user`, `tenant`, `panel` or
 * `everyone`, `subject` the id it is for ('' for everyone), `active` 1 or 0.
 * A read is one statement for the scope's four candidates, of which the most
 * specific set wins; Features keeps the answer for the rest of the request.
 *
 * Read on the connection given -- the Orm's own statements, which the raw
 * SQL check leaves alone, since the table is not a tenant's -- inside the
 * caller's read-only transaction when there is one. A row whose `active` is
 * not 0 or 1 fails the read: guessed, a malformed row could switch a feature
 * on for everyone.
 */
final readonly class DatabaseFlagValues implements FlagValues
{
    /** The parts a row may be for, most specific first. */
    private const array SCOPES = ['user', 'tenant', 'panel', 'everyone'];

    public function __construct(private Connection $connection, private string $table = 'feature_flags')
    {
        self::assertTable($table);
    }

    /** The table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'feature_flags', bool $ifNotExists = false): void
    {
        self::assertTable($table);

        $schema->create($table, static function (Table $flags): void {
            $flags->string('flag', 64);
            $flags->string('scope', 16);
            // A user's id, a tenant's name or a panel's: up to what an index
            // over three columns takes on MySQL's utf8mb4.
            $flags->string('subject', 191);
            $flags->integer('active');
            $flags->primary(['flag', 'scope', 'subject']);
        }, $ifNotExists);
    }

    public function valueFor(string $flag, FlagScope $scope): ?bool
    {
        $where = ["(scope = 'everyone' AND subject = '')"];
        $bindings = [$flag];
        foreach (['user' => $scope->user, 'tenant' => $scope->tenant === '' ? null : $scope->tenant, 'panel' => $scope->panel] as $part => $subject) {
            if ($subject !== null && $subject !== '') {
                $where[] = "(scope = '{$part}' AND subject = ?)";
                $bindings[] = $subject;
            }
        }

        $rows = $this->connection->select(
            "SELECT scope, active FROM {$this->table} WHERE flag = ? AND (" . implode(' OR ', $where) . ')',
            $bindings,
        );

        $found = [];
        foreach ($rows as $row) {
            $active = $row['active'] ?? null;
            if (!\in_array($active, [0, 1, '0', '1'], true) || !\in_array($row['scope'] ?? null, self::SCOPES, true)) {
                throw new \UnexpectedValueException("{$this->table} holds a row for flag '{$flag}' that is not a scope with 0 or 1.");
            }
            $found[(string) $row['scope']] = (int) $active === 1;
        }

        foreach (self::SCOPES as $part) {
            if (isset($found[$part])) {
                return $found[$part];
            }
        }

        return null;
    }

    /**
     * Sets $flag for one part -- `user`, `tenant` or `panel` with its id, or
     * `everyone` with '' -- or, with null, takes the value away.
     */
    public function set(string $flag, string $scope, string $subject, ?bool $active): void
    {
        if (!\in_array($scope, self::SCOPES, true) || ($scope === 'everyone') !== ($subject === '')) {
            throw new \InvalidArgumentException("A flag is set for a user, tenant or panel by its id, or for everyone with ''; not '{$scope}' and '{$subject}'.");
        }
        new Flag($flag);

        $this->connection->transaction(function () use ($flag, $scope, $subject, $active): void {
            $this->connection->execute("DELETE FROM {$this->table} WHERE flag = ? AND scope = ? AND subject = ?", [$flag, $scope, $subject]);
            if ($active !== null) {
                $this->connection->execute(
                    "INSERT INTO {$this->table} (flag, scope, subject, active) VALUES (?, ?, ?, ?)",
                    [$flag, $scope, $subject, $active ? 1 : 0],
                );
            }
        });
    }

    private static function assertTable(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will read.");
        }
    }
}
