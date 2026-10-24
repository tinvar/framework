<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Claims in a table of the application's database, so schedulers on
 * several servers share them: the first to insert a task's minute runs it,
 * and the rest find the row there. SQLite and Postgres alike, by
 * `INSERT ... ON CONFLICT DO NOTHING`.
 */
final readonly class DatabaseScheduleStore implements ScheduleStore
{
    /** How long a claim is kept: long enough that no scheduler is still deciding about that minute. */
    public const int KEEP_SECONDS = 86_400;

    public function __construct(private Connection $connection, private string $table = 'schedule_runs')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'schedule_runs', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $runs): void {
            $runs->string('task');
            $runs->bigInteger('minute');
            $runs->primary(['task', 'minute']);
        }, $ifNotExists);
    }

    public function claim(string $task, int $minute): bool
    {
        $claimed = $this->connection->execute(
            "INSERT INTO {$this->table} (task, minute) VALUES (?, ?) ON CONFLICT DO NOTHING",
            [$task, $minute],
        ) === 1;

        if ($claimed) {
            $this->connection->execute("DELETE FROM {$this->table} WHERE minute < ?", [$minute - self::KEEP_SECONDS]);
        }

        return $claimed;
    }
}
