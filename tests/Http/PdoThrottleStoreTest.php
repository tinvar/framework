<?php

declare(strict_types=1);

/**
 * PdoThrottleStore: counters in a table every server reaches, so a limit
 * behind a load balancer is one limit and not one per server.
 *
 * Each test runs against SQLite, and against Postgres and MySQL when they
 * are there (tests/Support/Fixtures/databases.php says how they are found),
 * skipped with the reason when they are not.
 */

namespace Tinvar\Tests\Http\PdoThrottleStore;

use Tinvar\Http\PdoThrottleStore;
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
 * Runs $test with a connection to an empty database of the driver's, the
 * DSN a second connection or a child process opens it with, and the table
 * name to give a store there.
 *
 * @param \Closure(\PDO, string, string): void $test given the connection, its DSN and the table
 */
function onDriver(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-pdo-throttle-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $dsn = "sqlite:{$directory}/throttle.sqlite";

        try {
            $test(sqlite($dsn), $dsn, PdoThrottleStore::TABLE);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }

        return;
    }

    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $test): void {
        $test($pdo, Databases::dsn($driver), "{$schema}." . PdoThrottleStore::TABLE);
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

function another(string $driver, string $dsn): \PDO
{
    return $driver === 'sqlite' ? sqlite($dsn) : Databases::another($driver);
}

/** @param (\Closure(): int)|null $clock */
function installed(\PDO $pdo, string $table, ?\Closure $clock = null): PdoThrottleStore
{
    $store = new PdoThrottleStore($pdo, $table, $clock);
    $store->install();

    return $store;
}

it('counts hits in a window aligned to the clock, and starts again in the next', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        $now = 1_000_030;
        $store = installed($pdo, $table, static function () use (&$now): int {
            return $now;
        });

        expect($store->increment('k', 60))->toBe([1, 1_000_080])
            ->and($store->increment('k', 60))->toBe([2, 1_000_080])
            ->and($store->increment('other', 60))->toBe([1, 1_000_080])
            ->and($store->increment('k', 900)[0])->toBe(1, 'the same key over another window is another count');

        $now += 60;
        expect($store->increment('k', 60))->toBe([1, 1_000_140]);
    });
})->with(DRIVERS);

it('takes back hits in the current window, never below zero and never opening one', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        $now = 1_000_000;
        $store = installed($pdo, $table, static function () use (&$now): int {
            return $now;
        });

        $store->increment('k', 60);
        $store->increment('k', 60);
        $store->release('k', 60);
        expect($store->increment('k', 60)[0])->toBe(2);

        $store->release('k', 60, 5);
        expect($store->increment('k', 60)[0])->toBe(1, 'released past zero, it stopped at zero');

        $store->release('k', 60, PHP_INT_MAX);
        expect($store->increment('k', 60)[0])->toBe(1, 'cleared');

        $store->release('never-counted', 60);
        expect($store->increment('never-counted', 60)[0])->toBe(1);

        $now += 60;
        $store->release('k', 60);
        expect($store->increment('k', 60)[0])->toBe(1, 'a passed window gives nothing back to the next');
    });
})->with(DRIVERS);

// Every new window makes a row; the hit that makes one clears a few that have
// passed, so the table cannot grow with every caller ever seen.
it('sweeps passed windows a bounded batch at a time', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        $now = 1_000_000;
        $store = installed($pdo, $table, static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < PdoThrottleStore::SWEEP_BATCH * 2; $i++) {
            $store->increment("caller-{$i}", 60);
        }

        $now += 120;
        $store->increment('next', 60);
        expect($store->count())->toBe(PdoThrottleStore::SWEEP_BATCH + 1);

        $store->increment('another', 60);
        $store->increment('another', 60);
        expect($store->count())->toBe(2, 'swept by the new window, and not by a second hit in it');
    });
})->with(DRIVERS);

// The point of the store: servers counting on connections of their own, all
// against the one budget.
it('shares its counts with every store on the same table', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
        installed($pdo, $table)->increment('k', 60);
        new PdoThrottleStore(another($driver, $dsn), $table)->increment('k', 60);

        expect(new PdoThrottleStore(another($driver, $dsn), $table)->increment('k', 60)[0])->toBe(3);
    });
})->with(DRIVERS);

it('keeps the caller\'s key out of the table', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        installed($pdo, $table)->increment('actions|ip|203.0.113.7', 60);

        $entries = $pdo->query("SELECT entry FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN);

        expect($entries)->toHaveCount(1)
            ->and(implode('', $entries))->not->toContain('203.0.113.7');
    });
})->with(DRIVERS);

// A count inside the request's transaction would roll back with it: a failed
// sign-in counted, then undone by the action that failed.
it('refuses to count on a connection with a transaction open', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        $store = installed($pdo, $table);
        $pdo->beginTransaction();

        try {
            expect(fn () => $store->increment('k', 60))->toThrow(\LogicException::class, 'connection of its own')
                ->and(fn () => $store->release('k', 60))->toThrow(\LogicException::class, 'connection of its own');
        } finally {
            $pdo->rollBack();
        }
    });
})->with(DRIVERS);

it('makes its table only once, however often it is installed', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
        $store = installed($pdo, $table);
        $store->increment('k', 60);
        $store->install();

        expect($store->increment('k', 60)[0])->toBe(2);
    });
})->with(DRIVERS);

it('opens nothing until it counts', function () {
    $opened = 0;
    $store = new PdoThrottleStore(static function () use (&$opened): \PDO {
        $opened++;
        $pdo = new \PDO('sqlite::memory:');
        new PdoThrottleStore($pdo)->install();

        return $pdo;
    });

    expect($opened)->toBe(0);

    $store->increment('k', 60);
    $store->increment('k', 60);
    expect($opened)->toBe(1);
});

it('refuses a table name that is not a plain identifier', function (string $table) {
    expect(fn () => new PdoThrottleStore(new \PDO('sqlite::memory:'), $table))
        ->toThrow(\InvalidArgumentException::class)
        ->and(fn () => PdoThrottleStore::schema('pgsql', $table))
        ->toThrow(\InvalidArgumentException::class);
})->with(['', 'throttle; DROP TABLE users', 'a.b.c', '"quoted"', '1st']);

it('names the drivers it keeps counts in', function () {
    expect(fn () => PdoThrottleStore::schema('sqlsrv'))->toThrow(\InvalidArgumentException::class, 'Postgres, MySQL or SQLite')
        ->and(PdoThrottleStore::schema('pgsql'))->each->toContain('tinvar_throttle');
});

// Atomic, or the limit is advisory: processes counting one key at once must
// between them see every count from 1 to N exactly once. Each also opens
// windows of a second for callers of its own, so sweeps run side by side.
it('counts every hit once across processes counting at the same moment', function (string $driver) {
    $missing = missing($driver);
    if ($missing !== null) {
        $this->markTestSkipped($missing);
    }

    onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
        installed($pdo, $table);
        $processes = 6;
        $each = 40;
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sys_get_temp_dir() . '/tinvar-pdo-throttle-count-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $driver, $dsn, $table, $start, $each] = $argv;
            require $autoload;
            $pdo = new PDO($dsn);
            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA busy_timeout = 10000');
            }
            $store = new Tinvar\Http\PdoThrottleStore($pdo, $table);
            while (microtime(true) < (float) $start) {
                usleep(1000);
            }
            $counts = [];
            for ($i = 0; $i < (int) $each; $i++) {
                $counts[] = $store->increment('shared', 3600)[0];
                $store->increment('caller-' . getmypid() . "-{$i}", 1);
            }
            echo json_encode($counts);
            PHP);

        try {
            // Started together, after every process has booted.
            $start = (string) (microtime(true) + 1.5);
            $running = [];
            $outputs = [];
            for ($p = 0; $p < $processes; $p++) {
                $running[] = proc_open(
                    [PHP_BINARY, $script, $autoload, $driver, $dsn, $table, $start, (string) $each],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                ) ?: throw new \RuntimeException('Could not start a counting process.');
                $outputs[] = $pipes;
            }

            $counts = [];
            foreach ($running as $p => $process) {
                $out = stream_get_contents($outputs[$p][1]);
                $err = stream_get_contents($outputs[$p][2]);
                proc_close($process);
                expect($err)->toBe('');
                array_push($counts, ...json_decode((string) $out, true, flags: JSON_THROW_ON_ERROR));
            }
            sort($counts);

            expect($counts)->toBe(range(1, $processes * $each));
        } finally {
            unlink($script);
        }
    });
})->with(DRIVERS);
