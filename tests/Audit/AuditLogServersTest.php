<?php

declare(strict_types=1);

/**
 * The audit log (ADR 0151) on the servers: its tables made by each
 * dialect, a moment hashed the same as the server stores it, an entry
 * rolled back with its change, and two writers to one tenant's log chained
 * one after the other -- the second waits on the head row's lock while the
 * first's transaction is open, and chains to its entry once it commits.
 *
 * Skipped, saying why, without a server (tests/Support/Fixtures/databases.php
 * says how one is found).
 */

namespace Tinvar\Tests\Audit\AuditLogServers;

use Tinvar\Audit\AuditLog;
use Tinvar\Audit\AuditTable;
use Tinvar\Auth\AuthContext;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const KEY = 'audit-servers-test-key-of-at-least-32-bytes';

#[Table('audit_server_notes'), Audited]
final class ServerNote extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

function skipWithout(string $driver): void
{
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
}

/** @return array{Orm, AuditLog} */
function logOn(\PDO $pdo, string $driver): array
{
    $connection = new PdoConnection($pdo, $driver);
    $orm = new Orm($connection, new IdentityMap($driver . bin2hex(random_bytes(3))));

    return [$orm, new AuditLog($orm, new AuthContext(), KEY)];
}

function note(Orm $orm, string $body): ServerNote
{
    $note = new ServerNote();
    $note->body = $body;

    return $orm->save($note);
}

it('writes, rolls back and verifies a log on the server', function (string $driver) {
    skipWithout($driver);
    Databases::inScratchSchema($driver, function (\PDO $pdo) use ($driver): void {
        $connection = new PdoConnection($pdo, $driver);
        $connection->execute('CREATE TABLE audit_server_notes (id VARCHAR(36) PRIMARY KEY, body VARCHAR(255) NOT NULL)');
        AuditTable::define(new Schema($connection, Dialect::for($connection)));
        [$orm, $audit] = logOn($pdo, $driver);

        $note = note($orm, 'one');
        $note->body = 'two';
        $orm->save($note);
        expect(fn () => $orm->transaction(function () use ($orm): void {
            note($orm, 'taken back');
            throw new \RuntimeException('no');
        }))->toThrow(\RuntimeException::class);

        $entries = $audit->trail()->forSubject($note);
        expect(array_map(static fn ($e): string => $e->event, $entries))->toBe(['updated', 'created'])
            ->and($entries[0]->changes)->toBe(['body' => ['one', 'two']])
            ->and(\count($audit->trail()->recent()))->toBe(2)
            ->and($audit->verify('')->intact)->toBeTrue()
            ->and($audit->verify('')->entries)->toBe(2);
    });
})->with(['pgsql', 'mysql']);

it('chains a second writer after the first, which holds the head until it commits', function (string $driver) {
    skipWithout($driver);
    Databases::inScratchSchema($driver, function (\PDO $pdo, string $schema) use ($driver): void {
        $connection = new PdoConnection($pdo, $driver);
        $connection->execute('CREATE TABLE audit_server_notes (id VARCHAR(36) PRIMARY KEY, body VARCHAR(255) NOT NULL)');
        AuditTable::define(new Schema($connection, Dialect::for($connection)));
        [$first, $audit] = logOn($pdo, $driver);
        note($first, 'made first, to make the head');

        $other = Databases::another($driver);
        $other->exec($driver === 'pgsql' ? "SET search_path TO {$schema}" : "USE {$schema}");
        $other->exec($driver === 'pgsql' ? "SET lock_timeout = '300ms'" : 'SET innodb_lock_wait_timeout = 1');
        [$second] = logOn($other, $driver);

        $first->transaction(function () use ($first, $second): void {
            note($first, 'first writer');
            // The head row is the first writer's until it commits: the
            // second cannot chain beside it, and gives up waiting.
            expect(fn () => note($second, 'second writer'))->toThrow(\PDOException::class);
        });
        note($second, 'second writer, after');

        $sequences = array_map(static fn ($e): int => $e->sequence, $audit->trail()->recent());
        expect($sequences)->toBe([3, 2, 1])
            ->and($audit->verify('')->intact)->toBeTrue();
    });
})->with(['pgsql', 'mysql']);
