<?php

declare(strict_types=1);

/**
 * PdoStore: one cache for every server, in tables they all reach. Two
 * stores on two connections stand in for two servers.
 *
 * Each test runs against SQLite, and against Postgres and MySQL when they
 * are there (tests/Support/Fixtures/databases.php says how they are found),
 * skipped with the reason when they are not.
 */

namespace Tinvar\Tests\Cache\PdoStore;

use Tinvar\Cache\PdoStore;
use Tinvar\Codec\Bin;
use Tinvar\Codec\MessagePack;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const DRIVERS = ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];

/** Why a driver's tests cannot run here, or null when they can. */
function missing(string $driver): ?string
{
    return match ($driver) {
        'pgsql' => Databases::postgresMissing(),
        'mysql' => Databases::mysqlMissing(),
        default => null,
    };
}

/**
 * Runs $test with a connection to an empty database of the driver's, a way
 * to open a second one -- another server -- and the table name to give a
 * store there.
 *
 * @param \Closure(\PDO, \Closure(): \PDO, string): void $test
 */
function onDriver(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-pdo-cache-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $dsn = "sqlite:{$directory}/cache.sqlite";

        try {
            $test(sqlite($dsn), static fn (): \PDO => sqlite($dsn), PdoStore::TABLE);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }

        return;
    }

    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $test): void {
        $test($pdo, static fn (): \PDO => Databases::another($driver), "{$schema}." . PdoStore::TABLE);
    });
}

/** A SQLite connection that waits its turn for the write lock, as a store's must. */
function sqlite(string $dsn): \PDO
{
    $pdo = new \PDO($dsn);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

/** @param (\Closure(): int)|null $clock */
function installed(\PDO $pdo, string $table, ?\Closure $clock = null, int $maxBytes = PdoStore::MAX_BYTES, int $maxEntries = PdoStore::MAX_ENTRIES): PdoStore
{
    $store = new PdoStore($pdo, $table, $maxBytes, $maxEntries, $clock);
    $store->install();

    return $store;
}

/** Overwrites the payload kept for a key, as someone who can write the table could. */
function tamper(\PDO $pdo, string $table, string $entry, string $payload): void
{
    $statement = $pdo->prepare("UPDATE {$table} SET payload = ? WHERE entry = ?");
    $statement->bindValue(1, $payload, \PDO::PARAM_LOB);
    $statement->bindValue(2, $entry);
    $statement->execute();
    expect($statement->rowCount())->toBe(1);
}

/** @return list<string> */
function entries(\PDO $pdo, string $table): array
{
    return array_map('strval', $pdo->query("SELECT entry FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN));
}

it('hands one server what another put, exactly as it was, and misses what nobody put', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $first = installed($pdo, $table);
        $second = new PdoStore($another(), $table);
        $value = [
            'rows' => [['id' => 1, 'name' => "caf\xe9", 'price' => 12.5], ['id' => 2, 'name' => '', 'price' => -0.0]],
            'chunk' => new Bin("\x00\xff\x00"),
            'nested' => ['list' => [new Bin(''), null, true, false, PHP_INT_MIN]],
            7 => 'an integer key',
            'huge' => INF,
        ];

        $first->put('q:a', $value, 60, ['racks']);
        $first->put('q:bin', new Bin("\xc4\x01"), 60);
        $hit = $second->get('q:a');

        expect($hit)->toEqual(['hit' => true, 'value' => $value])
            ->and($hit['value']['chunk'])->toBeInstanceOf(Bin::class)
            ->and($hit['value']['rows'][0]['name'])->toBe("caf\xe9")
            ->and($hit['value']['rows'][0]['price'])->toBe(12.5)
            ->and($hit['value']['nested']['list'][4])->toBe(PHP_INT_MIN)
            ->and($second->get('q:bin'))->toEqual(['hit' => true, 'value' => new Bin("\xc4\x01")])
            ->and($second->get('q:nobody'))->toBe(['hit' => false, 'value' => null]);
    });
})->with(DRIVERS);

it('misses once an entry has expired, keeps one with no TTL, and none for a TTL of zero', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $now = 1_000_000;
        $store = installed($pdo, $table, static function () use (&$now): int {
            return $now;
        });
        $store->put('q:short', 'short', 60);
        $store->put('q:kept', 'kept');
        $store->put('q:zero', 'first', 60);
        $store->put('q:zero', 'second', 0);

        $now += 59;
        expect($store->get('q:short')['hit'])->toBeTrue()
            ->and($store->get('q:zero')['hit'])->toBeFalse();

        $now += 1;
        expect($store->get('q:short')['hit'])->toBeFalse()
            ->and($store->get('q:kept'))->toBe(['hit' => true, 'value' => 'kept']);
    });
})->with(DRIVERS);

// The point of the store: a forget on one server reaches every server.
it('misses on every server once any server forgets a tag the entry carries', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $first = installed($pdo, $table);
        $second = new PdoStore($another(), $table);
        $first->put('q:rack', 'rack r1', 300, ["acme\0rack:r1", 'racks']);
        $first->put('q:other', 'rack r2', 300, ["acme\0rack:r2"]);
        $first->put('q:plain', 'untagged', 300);

        expect($second->forgetTags(["acme\0rack:r1"]))->toBe(0, 'versions cannot count what they reached');

        expect($first->get('q:rack')['hit'])->toBeFalse()
            ->and($second->get('q:rack')['hit'])->toBeFalse()
            ->and($first->get('q:other'))->toBe(['hit' => true, 'value' => 'rack r2'])
            ->and($second->get('q:plain'))->toBe(['hit' => true, 'value' => 'untagged']);

        $first->put('q:rack', 'rack r1, again', 300, ["acme\0rack:r1"]);
        expect($second->get('q:rack'))->toBe(['hit' => true, 'value' => 'rack r1, again']);

        // And a re-put's tags replace the old ones.
        $first->put('q:rack', 'retagged', 300, ['new']);
        $second->forgetTags(["acme\0rack:r1"]);
        expect($first->get('q:rack'))->toBe(['hit' => true, 'value' => 'retagged']);
    });
})->with(DRIVERS);

it('forgets one key on every server, and everything on flush', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $first = installed($pdo, $table);
        $second = new PdoStore($another(), $table);
        $first->put('q:a', 'a', 60, ['t']);
        $first->put('q:b', 'b', 60, ['t']);

        $second->forget('q:a');
        expect($first->get('q:a')['hit'])->toBeFalse()
            ->and($first->get('q:b')['hit'])->toBeTrue();

        $second->flush();
        expect($first->get('q:b')['hit'])->toBeFalse()
            ->and($first->count())->toBe(0)
            ->and($pdo->query("SELECT tag FROM {$table}_tags")->fetchAll(\PDO::FETCH_COLUMN))->toBe(['!clock']);
    });
})->with(DRIVERS);

// A server misses and reads the rows; another commits a write and forgets
// the tag; the first puts what it read before the write. Kept, it would be
// served for the whole TTL.
it('keeps nothing read before a forget that landed after the miss', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $reader = installed($pdo, $table);
        $writer = new PdoStore($another(), $table);
        $reader->put('q:warm', 'warm', 60, ['racks']);

        expect($reader->get('q:a')['hit'])->toBeFalse();
        $writer->forgetTags(['racks']);
        $reader->put('q:a', 'from before the write', 60, ['racks']);
        expect($writer->get('q:a')['hit'])->toBeFalse();

        $reader->put('q:a', 'from after the write', 60, ['racks']);
        expect($writer->get('q:a'))->toBe(['hit' => true, 'value' => 'from after the write']);

        // Nothing it carries forgotten since its miss: kept.
        expect($reader->get('q:b')['hit'])->toBeFalse();
        $writer->forgetTags(['sites']);
        $reader->put('q:b', 'b', 60, ['racks']);
        expect($writer->get('q:b'))->toBe(['hit' => true, 'value' => 'b']);
    });
})->with(DRIVERS);

// The table is a trust boundary: whoever can write it must not choose what
// a read hands back beyond what a put could have.
it('refuses a payload that is not one it wrote, as a miss, and deletes it', function (string $driver, \Closure $payload) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table) use ($payload): void {
        $store = installed($pdo, $table);
        $store->put('q:a', ['n' => 1], 60);
        tamper($pdo, $table, 'q:a', $payload());

        expect($store->get('q:a'))->toBe(['hit' => false, 'value' => null])
            ->and(entries($pdo, $table))->toBe([]);
    });
})->with(DRIVERS)->with([
    'PHP serialize()' => [fn (): string => serialize(['n' => new \ArrayObject([1])])],
    'not MessagePack' => [fn (): string => "\xc1garbage"],
    'empty' => [fn (): string => ''],
    'a bare value' => [fn (): string => MessagePack::encode(['n' => 1])],
    'another format' => [fn (): string => MessagePack::encode([2, ['n' => 1], [], []])],
    'trailing bytes' => [fn (): string => MessagePack::encode([1, ['n' => 1], [], []]) . "\x00"],
    'a key the encoder refuses' => [fn (): string => MessagePack::encode([1, ['__proto__' => ['polluted' => true]], [], []])],
    'deeper than the encoder goes' => [fn (): string => MessagePack::encode([1, array_reduce(range(1, 40), static fn (mixed $v): array => [$v], 1), [], []])],
    'a Bin where there is no string' => [fn (): string => MessagePack::encode([1, ['n' => 1], [['n']], []])],
    'a Bin where there is nothing' => [fn (): string => MessagePack::encode([1, ['n' => 'x'], [['m']], []])],
    'one Bin listed twice' => [fn (): string => MessagePack::encode([1, ['n' => 'x'], [['n'], ['n']], []])],
    'a version that is not a number' => [fn (): string => MessagePack::encode([1, ['n' => 1], [], [['racks', 'one']]])],
    'a tag listed twice' => [fn (): string => MessagePack::encode([1, ['n' => 1], [], [['racks', 1], ['racks', 1]]])],
    'too many tags' => [fn (): string => MessagePack::encode([1, ['n' => 1], [], array_map(static fn (int $i): array => ["t{$i}", 1], range(1, 300))])],
]);

it('hands back a payload it wrote by hand only as the value it holds', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table);
        $store->put('q:a', 'placeholder', 60);
        tamper($pdo, $table, 'q:a', MessagePack::encode([1, ['n' => "\xff", 'b' => 'raw'], [['b']], []]));

        expect($store->get('q:a'))->toEqual(['hit' => true, 'value' => ['n' => "\xff", 'b' => new Bin('raw')]]);
    });
})->with(DRIVERS);

it('files long keys and tags hashed, and never under the key itself', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table);
        $key = 'q:' . str_repeat('k', 200) . "\0user@example.test";
        $store->put($key, 'v', 60, ["acme\0rack:1"]);
        $store->put('q:0123456789abcdef', 'short', 60);

        $names = [...entries($pdo, $table), ...$pdo->query("SELECT tag FROM {$table}_tags")->fetchAll(\PDO::FETCH_COLUMN)];
        foreach ($names as $name) {
            expect(\strlen((string) $name))->toBeLessThanOrEqual(65)
                ->and((string) $name)->not->toContain('user@example.test')
                ->and((string) $name)->not->toContain("\0");
        }

        // A short, plain key is kept as it is.
        expect(entries($pdo, $table))->toContain('q:0123456789abcdef')
            ->and($store->get($key))->toBe(['hit' => true, 'value' => 'v']);
    });
})->with(DRIVERS);

// Every put deletes a few entries that have expired, so they go at least as
// fast as they come.
it('sweeps expired entries a bounded batch at a time', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $now = 1_000_000;
        $store = installed($pdo, $table, static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < PdoStore::SWEEP_BATCH * 2; $i++) {
            $store->put("q:{$i}", $i, 60);
        }

        $now += 60;
        $store->put('q:next', 'next', 60);
        expect($store->count())->toBe(PdoStore::SWEEP_BATCH + 1);

        $store->put('q:next', 'again', 60);
        expect($store->count())->toBe(1);
    });
})->with(DRIVERS);

// A TTL can be a year, and keys can be minted faster than that.
it('keeps the table to its limit, dropping the entries nearest their expiry', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table, maxEntries: 10);
        $store->put('q:lasting', 'lasting', 86_400);
        for ($i = 1; $i < PdoStore::TRIM_EVERY; $i++) {
            $store->put("q:{$i}", $i, 60 + $i);
        }

        expect($store->count())->toBe(10)
            ->and($store->get('q:lasting')['hit'])->toBeTrue()
            ->and($store->get('q:' . (PdoStore::TRIM_EVERY - 1))['hit'])->toBeTrue()
            ->and($store->get('q:1')['hit'])->toBeFalse();
    });
})->with(DRIVERS);

it('keeps no value larger than its limit, and drops the one it replaced', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table, maxBytes: 1_000);
        $store->put('q:a', 'small', 60);
        $store->put('q:a', str_repeat('x', 1_000), 60);
        $store->put('q:object', new \ArrayObject(), 60);

        expect($store->get('q:a')['hit'])->toBeFalse()
            ->and($store->get('q:object')['hit'])->toBeFalse('nothing the encoder could not have produced is kept')
            ->and($store->count())->toBe(0);
    });
})->with(DRIVERS);

// The decoder reads at most so many elements; a value with more could be
// written but never read back.
it('keeps nothing it could not read back', function () {
    $store = new PdoStore(new \PDO('sqlite::memory:'));
    $store->install();

    $store->put('q:wide', range(1, MessagePack::MAX_ELEMENTS), 60);
    $store->put('q:fits', range(1, 1_000), 60);

    expect($store->get('q:wide')['hit'])->toBeFalse()
        ->and($store->get('q:fits')['hit'])->toBeTrue();
});

// A forget inside the request's transaction would roll back with it, and
// leave standing every entry it dropped.
it('refuses a connection with a transaction open', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table);
        $pdo->beginTransaction();

        try {
            expect(fn () => $store->forgetTags(['racks']))->toThrow(\LogicException::class, 'connection of its own')
                ->and(fn () => $store->put('q:a', 1, 60))->toThrow(\LogicException::class, 'connection of its own')
                ->and(fn () => $store->get('q:a'))->toThrow(\LogicException::class, 'connection of its own');
        } finally {
            $pdo->rollBack();
        }
    });
})->with(DRIVERS);

it('makes its tables only once, however often it is installed', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, \Closure $another, string $table): void {
        $store = installed($pdo, $table);
        $store->put('q:a', 'a', 60, ['racks']);
        $store->install();

        expect($store->get('q:a'))->toBe(['hit' => true, 'value' => 'a']);
    });
})->with(DRIVERS);

it('makes its tables and indexes in a schema whose name and its own are as long as it takes', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    Databases::inScratchSchema($driver, function (\PDO $pdo, string $schema): void {
        $store = installed($pdo, "{$schema}." . str_repeat('t', 40));
        $store->install();
        $store->put('q:a', 'a', 60, ['racks']);

        expect($store->get('q:a'))->toBe(['hit' => true, 'value' => 'a']);
    });
})->with(['pgsql' => ['pgsql'], 'mysql' => ['mysql']]);

it('starts its clock on first use where it was installed without one', function () {
    $pdo = new \PDO('sqlite::memory:');
    foreach (PdoStore::schema('sqlite') as $statement) {
        $pdo->exec($statement);
    }
    $store = new PdoStore($pdo);

    expect($store->get('q:a')['hit'])->toBeFalse();
    $store->put('q:a', 'a', 60, ['racks']);
    expect($store->get('q:a'))->toBe(['hit' => true, 'value' => 'a']);

    $store->forgetTags(['racks']);
    expect($store->get('q:a')['hit'])->toBeFalse();
});

it('opens nothing until it is used', function () {
    $opened = 0;
    $store = new PdoStore(static function () use (&$opened): \PDO {
        $opened++;
        $pdo = new \PDO('sqlite::memory:');
        new PdoStore($pdo)->install();

        return $pdo;
    });

    expect($opened)->toBe(0);

    $store->put('q:a', 'a', 60);
    $store->get('q:a');
    expect($opened)->toBe(1);
});

it('refuses a table name that is not a plain identifier', function (string $table) {
    expect(fn () => new PdoStore(new \PDO('sqlite::memory:'), $table))
        ->toThrow(\InvalidArgumentException::class)
        ->and(fn () => PdoStore::schema('pgsql', $table))
        ->toThrow(\InvalidArgumentException::class);
})->with(['', 'cache; DROP TABLE users', 'a.b.c', '"quoted"', '1st', 'a_table_name_long_enough_to_overflow_its_tag_table']);

it('names the drivers it keeps entries in', function () {
    expect(fn () => PdoStore::schema('sqlsrv'))->toThrow(\InvalidArgumentException::class, 'Postgres, MySQL or SQLite')
        ->and(PdoStore::schema('pgsql'))->each->toContain('tinvar_cache');
});
