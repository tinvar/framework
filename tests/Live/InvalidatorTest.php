<?php

declare(strict_types=1);

/**
 * Publishing what was invalidated: after the transaction commits, never
 * before, and to each tag's own topic in its tenant's namespace.
 *
 * Told first, a client asks for its page at once, reads the data as it was,
 * and is not told again. The hub here records the moment it is asked, and a
 * second connection to the same database says what anyone else could read
 * at that moment.
 */

namespace Tinvar\Tests\Live\Invalidator;

use Tinvar\Live\Invalidator;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\Topics;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'invalidator-test-application-key-0123456789';

/** @return array{Orm, \PDO, Topics} an ORM on a fresh file, and a second connection to it */
function database(): array
{
    $path = sys_get_temp_dir() . '/tinvar-live-' . bin2hex(random_bytes(4)) . '.sqlite';
    register_shutdown_function(static function () use ($path): void {
        foreach ([$path, "{$path}-wal", "{$path}-shm", "{$path}-journal"] as $file) {
            @unlink($file);
        }
    });

    $connection = PdoConnection::sqlite($path, 'test', create: true);
    $connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');

    return [new Orm($connection, new IdentityMap('test')), new \PDO("sqlite:{$path}"), new Topics(new Signer(KEY, Topics::PURPOSE))];
}

/** @return list<int> what anyone else reading the database sees */
function committed(\PDO $other): array
{
    return array_map(intval(...), $other->query('SELECT id FROM orders ORDER BY id')?->fetchAll(\PDO::FETCH_COLUMN) ?: []);
}

it('publishes once the transaction commits, when anyone else can read what was written', function () {
    [$orm, $other, $topics] = database();
    $seen = [];
    $hub = new MemoryHub(static function () use (&$seen, $other): void {
        $seen[] = committed($other);
    });
    $invalidator = new Invalidator($hub, $topics, $orm);

    $orm->transaction(function () use ($orm, $invalidator, $hub): void {
        $orm->connection()->execute('INSERT INTO orders (id) VALUES (1)');
        $invalidator->invalidate(['orders']);

        expect($hub->published)->toBe([]);
    });

    expect($hub->published)->toHaveCount(1)
        ->and($seen)->toBe([[1]]);
});

it('publishes nothing for writes that roll back', function () {
    [$orm, $other, $topics] = database();
    $hub = new MemoryHub();
    $invalidator = new Invalidator($hub, $topics, $orm);

    try {
        $orm->transaction(function () use ($orm, $invalidator): void {
            $orm->connection()->execute('INSERT INTO orders (id) VALUES (1)');
            $invalidator->invalidate(['orders']);

            throw new \RuntimeException('the write failed');
        });
    } catch (\RuntimeException) {
    }

    expect($hub->published)->toBe([])
        ->and(committed($other))->toBe([]);
});

it('waits for the outermost commit, and drops what a nested transaction rolled back', function () {
    [$orm, $other, $topics] = database();
    $hub = new MemoryHub();
    $invalidator = new Invalidator($hub, $topics, $orm);

    $orm->transaction(function () use ($orm, $invalidator, $hub): void {
        $orm->transaction(function () use ($orm, $invalidator): void {
            $orm->connection()->execute('INSERT INTO orders (id) VALUES (1)');
            $invalidator->invalidate(['kept']);
        });

        // Committed only as far as a savepoint: nobody else can read it yet.
        expect($hub->published)->toBe([]);

        try {
            $orm->transaction(function () use ($orm, $invalidator): void {
                $orm->connection()->execute('INSERT INTO orders (id) VALUES (2)');
                $invalidator->invalidate(['undone']);

                throw new \RuntimeException('the inner write failed');
            });
        } catch (\RuntimeException) {
        }
    });

    $tags = array_map(static fn (array $u): string => json_decode($u['data'], true)['tag'], $hub->published);
    expect($tags)->toBe(['kept'])
        ->and(committed($other))->toBe([1]);
});

it('publishes at once with no transaction open: the writes before it are committed already', function () {
    [$orm, , $topics] = database();
    $hub = new MemoryHub();

    new Invalidator($hub, $topics, $orm)->invalidate(['orders']);

    expect($hub->published)->toHaveCount(1);
});

it('sends each tag to its own topic in the tenant\'s namespace, with the topic and the tag', function () {
    [$orm, , $topics] = database();
    $hub = new MemoryHub();
    $invalidator = new Invalidator($hub, $topics, $orm);

    $invalidator->invalidate(['orders', 'orders:customer:42', '', 'orders'], 't-1');
    $invalidator->invalidate(['orders'], 't-2');

    expect($hub->published)->toBe([
        ['topic' => $topics->for('t-1', 'orders'), 'data' => json_encode(['topic' => $topics->for('t-1', 'orders'), 'tag' => 'orders'])],
        [
            'topic' => $topics->for('t-1', 'orders:customer:42'),
            'data' => json_encode(['topic' => $topics->for('t-1', 'orders:customer:42'), 'tag' => 'orders:customer:42']),
        ],
        ['topic' => $topics->for('t-2', 'orders'), 'data' => json_encode(['topic' => $topics->for('t-2', 'orders'), 'tag' => 'orders'])],
    ])->and($topics->for('t-1', 'orders'))->not->toBe($topics->for('t-2', 'orders'));
});

it('reports a hub that fails, rather than failing the write that is already committed', function () {
    [$orm, $other, $topics] = database();
    $reported = [];
    $hub = new MemoryHub(static function (string $topic): void {
        throw new \RuntimeException("the hub is down ({$topic})");
    });
    $invalidator = new Invalidator($hub, $topics, $orm, static function (\Throwable $e) use (&$reported): string {
        $reported[] = $e->getMessage();

        return 'digest';
    });

    $orm->transaction(function () use ($orm, $invalidator): void {
        $orm->connection()->execute('INSERT INTO orders (id) VALUES (1)');
        $invalidator->invalidate(['a', 'b']);
    });

    expect($reported)->toHaveCount(2)
        ->and(committed($other))->toBe([1]);
});
