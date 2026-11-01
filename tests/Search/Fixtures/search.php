<?php

declare(strict_types=1);

namespace Tinvar\Tests\Search\Fixtures;

use Tinvar\Attributes\Searchable;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Search\DatabaseSearch;
use Tinvar\Search\Search;
use Tinvar\Search\SearchBackend;
use Tinvar\Search\SearchTables;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

/** A note: every reader's, not tenant-scoped; its title weighs three times its body. */
#[Table('notes'), Searchable(fields: ['title', 'body'], weights: ['title' => 3])]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $title;
    #[Column] public string $body;
    #[Column] public bool $archived = false;
}

/** A support ticket: one tenant's, held to it by the ORM and, on Postgres, by row-level security. */
#[Table('tickets'), TenantScoped, Searchable(fields: ['subject', 'body'], weights: ['subject' => 2])]
final class Ticket extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $subject;
    #[Column] public string $body;
}

/** A model with no #[Searchable]. */
#[Table('plain_rows')]
final class PlainRow extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

/** A searchable model naming a field it does not have. */
#[Table('broken_rows'), Searchable(fields: ['name', 'missing'])]
final class BrokenRow extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

/** The fixture tables, and the index's, on $connection's database. */
function createTables(Connection $connection, string $driver): void
{
    $connection->execute('CREATE TABLE notes (id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL, archived BOOLEAN NOT NULL DEFAULT FALSE)');
    $connection->execute('CREATE TABLE tickets (id TEXT PRIMARY KEY, subject TEXT NOT NULL, body TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    foreach (SearchTables::statements($driver) as $sql) {
        $connection->execute($sql);
    }
}

/**
 * An Orm over a fresh in-memory SQLite database with the fixture tables,
 * tenancy registered, and a Search over it observing every write.
 *
 * @return array{Orm, Search, PdoConnection}
 */
function sqliteSearch(?SearchBackend $backend = null, ?array $parents = null, mixed ...$options): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    createTables($connection, 'sqlite');
    $orm = new Orm($connection, new IdentityMap('search'), $parents === null ? Tenancy::flat() : Tenancy::tree($parents));
    $search = new Search($orm, $backend ?? DatabaseSearch::for($connection), [Note::class, Ticket::class, PlainRow::class], ...$options);

    return [$orm, $search->observe(), $connection];
}

function note(Orm $orm, string $title, string $body = ''): Note
{
    $note = new Note();
    $note->title = $title;
    $note->body = $body;

    return $orm->save($note);
}

function ticket(Orm $orm, string $subject, string $body = ''): Ticket
{
    $ticket = new Ticket();
    $ticket->subject = $subject;
    $ticket->body = $body;

    return $orm->save($ticket);
}

/**
 * The subjects or titles of models, in their order.
 *
 * @param iterable<Model> $models
 * @return list<string>
 */
function names(iterable $models): array
{
    $names = [];
    foreach ($models as $model) {
        $names[] = $model instanceof Ticket ? $model->subject : ($model instanceof Note ? $model->title : '?');
    }

    return $names;
}
