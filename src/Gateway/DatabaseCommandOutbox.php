<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;

/**
 * The outbox as a table in the application's own database.
 *
 * On the application's connection an append is a write like any other:
 * inside a transaction it commits or rolls back with the writes that
 * decided on the command, so a command exists exactly when they do. The
 * table is the application's to make, in a migration that calls define(),
 * and the bus's database role is granted SELECT on it and nothing else
 * (docs/deployment.md).
 */
final readonly class DatabaseCommandOutbox implements CommandOutbox
{
    public function __construct(
        private Connection $connection,
        private string $table = 'command_outbox',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this outbox will write.");
        }
    }

    /** The outbox's table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = 'command_outbox', bool $ifNotExists = false): void
    {
        new self($schema->connection(), $table);

        $schema->create($table, static function (Table $outbox) use ($table): void {
            $outbox->string('id', 64)->primary();
            $outbox->string('node', 128);
            $outbox->text('sealed');
            $outbox->bigInteger('created_at');
            // What a reader asks: everything from a moment on, in order.
            $outbox->index(['created_at', 'id'], "{$table}_created");
        }, $ifNotExists);
    }

    public function append(NodeCommand $command, string $sealed): void
    {
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, node, sealed, created_at) VALUES (?, ?, ?, ?)",
            [$command->id, $command->node, $sealed, $command->issuedAt],
        );
    }

    public function since(int $since, int $limit = 100): array
    {
        $rows = $this->connection->select(
            "SELECT id, node, sealed, created_at FROM {$this->table} WHERE created_at >= ? ORDER BY created_at, id LIMIT " . max(1, min($limit, 1000)),
            [$since],
        );

        return array_map(static fn (array $row): OutboxEntry => new OutboxEntry(
            (string) $row['id'],
            (string) $row['node'],
            (string) $row['sealed'],
            (int) $row['created_at'],
        ), $rows);
    }
}
