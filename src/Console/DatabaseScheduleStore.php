<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * Claims in a table of the application's database, so schedulers on
 * several servers share them: the first to insert a task's minute runs it,
 * and the rest find the row there. By `INSERT ... ON CONFLICT DO NOTHING`
 * on SQLite and Postgres, `INSERT IGNORE` on MySQL.
 */
final readonly class DatabaseScheduleStore implements ScheduleStore
{
    /** How long a claim is kept: long enough that no scheduler is still deciding about that minute. */
    public const int KEEP_SECONDS = 86_400;

    /** The longest task name the table holds whole, in bytes. */
    public const int MAX_TASK_BYTES = 255;

    private string $insert;

    public function __construct(private Connection $connection, private string $table = 'schedule_runs')
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this store will write.");
        }
        $this->insert = Dialect::for($connection)->insertUnlessTaken($table, ['task', 'minute']);
    }

    /** The store's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'schedule_runs', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $runs): void {
            $runs->string('task', self::MAX_TASK_BYTES);
            $runs->bigInteger('minute');
            $runs->primary(['task', 'minute']);
        }, $ifNotExists);
    }

    /**
     * A task named longer than its column is refused outright: MySQL's
     * INSERT IGNORE would cut the name short, and two tasks sharing their
     * first 255 bytes would claim one minute between them, so one of them
     * would never run.
     */
    public function claim(string $task, int $minute): bool
    {
        if (\strlen($task) > self::MAX_TASK_BYTES) {
            throw new \InvalidArgumentException(\sprintf('A scheduled task is named in %d bytes or fewer to be claimed; %s is not.', self::MAX_TASK_BYTES, $task));
        }

        $claimed = $this->connection->execute($this->insert, [$task, $minute]) === 1;

        if ($claimed) {
            $this->connection->execute("DELETE FROM {$this->table} WHERE minute < ?", [$minute - self::KEEP_SECONDS]);
        }

        return $claimed;
    }
}
