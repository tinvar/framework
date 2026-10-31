<?php

declare(strict_types=1);

/**
 * PdoBuildStore: one build history every server reads, so `tinvar
 * build:revoke` run on any machine refuses the build on all of them.
 *
 * Each test runs against SQLite, and against Postgres and MySQL when they
 * are there (tests/Support/Fixtures/databases.php says how they are found),
 * skipped with the reason when they are not.
 */

namespace Tinvar\Tests\Http\PdoBuildStore;

use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FileBuildStore;
use Tinvar\Http\PdoBuildStore;
use Tinvar\Server\BuildCommand;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const DRIVERS = ['sqlite' => ['sqlite'], 'pgsql' => ['pgsql'], 'mysql' => ['mysql']];

const NOW = 1_800_000_000;

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
 * DSN another server's connection opens it with, and the table to use.
 *
 * @param \Closure(\PDO, string, string): void $test
 */
function onDriver(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-pdo-builds-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $dsn = "sqlite:{$directory}/builds.sqlite";

        try {
            $test(connect('sqlite', $dsn), $dsn, PdoBuildStore::TABLE);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }

        return;
    }

    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $test): void {
        $test($pdo, Databases::dsn($driver), "{$schema}." . PdoBuildStore::TABLE);
    });
}

/** Another server's connection to the same database. */
function connect(string $driver, string $dsn): \PDO
{
    if ($driver !== 'sqlite') {
        return Databases::another($driver);
    }
    $pdo = new \PDO($dsn);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

function installed(\PDO $pdo, string $table): PdoBuildStore
{
    $store = new PdoBuildStore($pdo, $table);
    $store->install();

    return $store;
}

/** The operator's commands over $store, printing nowhere. */
function command(PdoBuildStore $store): BuildCommand
{
    return new BuildCommand($store, static function (): void {}, static function (): void {}, static fn (): int => NOW);
}

describe('PdoBuildStore', function () {
    it('reads an empty history before anything is recorded, the same one each time', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
            $store = installed($pdo, $table);

            expect($store->read()->builds)->toBe([])
                ->and($store->read())->toBe($store->read());
        });
    })->with(DRIVERS);

    // Two servers, each with its own connection and its own registry, booted
    // before the revocation: the one that did not run the command refuses
    // the build on its next check, with nothing restarted.
    it('puts a revocation made on one server in force on another', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $here = installed($pdo, $table);
            $there = new PdoBuildStore(connect($driver, $dsn), $table);
            $command = command($here);
            $command->run('build:current', ['b1']);
            $command->run('build:current', ['b2']);

            $elsewhere = BuildRegistry::persisted('b2', $there, clock: static fn (): int => NOW);
            expect($elsewhere->accepts('b1'))->toBeTrue();

            expect($command->run('build:revoke', ['b1']))->toBe(0);
            // On its next request.
            $elsewhere->endRequest();
            expect($elsewhere->standing('b1'))->toBe(BuildRegistry::REVOKED)
                ->and($elsewhere->accepts('b2'))->toBeTrue();
        });
    })->with(DRIVERS);

    it('keeps a revoked build revoked across pruning, on every server', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $here = installed($pdo, $table);
            $here->update(static fn (): BuildHistory => new BuildHistory([
                ['id' => 'b1', 'at' => NOW - 30 * 86_400],
                ['id' => 'b2', 'at' => NOW - 20 * 86_400],
                ['id' => 'b3', 'at' => NOW - 10 * 86_400],
            ], revoked: ['b1']));
            command($here)->run('build:prune', ['--keep=1']);

            $there = new PdoBuildStore(connect($driver, $dsn), $table);

            expect($there->read()->pruned)->toBe(['b1', 'b2'])
                ->and(BuildRegistry::persisted('b3', $there)->standing('b1'))->toBe(BuildRegistry::REVOKED)
                // A server never replaced, still running b1, refuses it too.
                ->and(BuildRegistry::persisted('b1', $there)->accepts('b1'))->toBeFalse();
        });
    })->with(DRIVERS);

    // A revocation is never blocked: recorded on one server against a build
    // already pruned, it is in force on another -- the one still running
    // that build among them.
    it('honours a revocation of a pruned build made on another server', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $here = installed($pdo, $table);
            $here->update(static fn (): BuildHistory => new BuildHistory([
                ['id' => 'b1', 'at' => NOW - 30 * 86_400],
                ['id' => 'b2', 'at' => NOW - 20 * 86_400],
                ['id' => 'b3', 'at' => NOW - 10 * 86_400],
            ]));
            $command = command($here);
            $command->run('build:prune', ['--keep=1']);
            $there = new PdoBuildStore(connect($driver, $dsn), $table);
            $straggler = BuildRegistry::persisted('b2', $there);
            expect($straggler->standing('b2'))->toBe(BuildRegistry::PRUNED);

            expect($command->run('build:revoke', ['b2']))->toBe(0);
            $straggler->endRequest();
            expect($there->read()->revoked)->toBe(['b2'])
                ->and($straggler->standing('b2'))->toBe(BuildRegistry::REVOKED)
                ->and(BuildRegistry::persisted('b3', $there)->standing('b2'))->toBe(BuildRegistry::REVOKED);
        });
    })->with(DRIVERS);

    it('parses once while nothing changes, and again after any write', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $here = installed($pdo, $table);
            $there = new PdoBuildStore(connect($driver, $dsn), $table);
            $here->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', NOW));
            $first = $there->read();

            // The same history written again is still a write: a new revision.
            $here->update(static fn (BuildHistory $h): BuildHistory => $h);

            expect($there->read())->not->toBe($first)
                ->and($there->read())->toBe($there->read())
                ->and($there->read()->current())->toBe('b1');
        });
    })->with(DRIVERS);

    // The row deleted and written again holds a history this server never
    // saw. A counter would start over and match what was cached; a random
    // revision does not.
    it('sees a history written again from nothing', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $here = installed($pdo, $table);
            $there = new PdoBuildStore(connect($driver, $dsn), $table);
            $here->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', NOW));
            expect($there->read()->revoked)->toBe([]);

            $pdo->exec("DELETE FROM {$table}");
            $here->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', NOW)->released('b2', NOW)->revoking('b1'));

            expect($there->read()->revoked)->toBe(['b1']);
        });
    })->with(DRIVERS);

    it('refuses a row that is not a history, and a missing table, so a registry fails closed', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table): void {
            $store = installed($pdo, $table);
            $pdo->prepare("INSERT INTO {$table} (id, revision, history) VALUES (1, 'x', ?)")->execute(['{"format":1,"builds":{"b1":1},"revoked":[]}']);
            $registry = BuildRegistry::persisted('b2', $store);

            expect(fn () => $store->read())->toThrow(\RuntimeException::class, 'cannot be read')
                ->and(fn () => $store->update(static fn (BuildHistory $h): BuildHistory => $h))->toThrow(\RuntimeException::class)
                // Answering for its own build alone: every other is refused.
                ->and($registry->accepts('b1'))->toBeFalse()
                ->and($registry->accepts('b2'))->toBeTrue()
                ->and(fn () => new PdoBuildStore($pdo, $table . '_missing')->read())->toThrow(\RuntimeException::class);
        });
    })->with(DRIVERS);

    // A worker keeps its store for request after request. The server
    // dropping the store's connection -- a restart, a failover -- fails the
    // read under way; kept, that connection failed every read after it, and
    // the worker answered for its own build alone until it was restarted.
    it('opens its connection again once the server has dropped it', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            command(installed($pdo, $table))->run('build:current', ['b1']);
            command(installed($pdo, $table))->run('build:current', ['b2']);
            /** @var list<\PDO> $opened */
            $opened = [];
            $store = new PdoBuildStore(static function () use ($driver, &$opened): \PDO {
                return $opened[] = Databases::another($driver);
            }, $table);
            $registry = BuildRegistry::persisted('b2', $store, clock: static fn (): int => NOW);
            expect($registry->accepts('b1'))->toBeTrue();

            $id = $opened[0]->query($driver === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')?->fetchColumn();
            $pdo->exec($driver === 'pgsql' ? "SELECT pg_terminate_backend({$id})" : "KILL {$id}");

            // The read the drop interrupts fails closed...
            $registry->endRequest();
            expect($registry->accepts('b1'))->toBeFalse();
            // ...and the next request's is answered on a connection of its own.
            $registry->endRequest();
            expect($registry->accepts('b1'))->toBeTrue()
                ->and($opened)->toHaveCount(2);
        });
    })->with(['pgsql' => ['pgsql'], 'mysql' => ['mysql']]);

    it('takes a file\'s history when it has recorded nothing, and never over one it has', function () {
        $pdo = new \PDO('sqlite::memory:');
        $store = installed($pdo, PdoBuildStore::TABLE);
        $directory = sys_get_temp_dir() . '/tinvar-pdo-adopt-' . bin2hex(random_bytes(4));
        $file = new FileBuildStore("{$directory}/builds.json");
        $file->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', NOW)->released('b2', NOW)->revoking('b1'));

        try {
            expect($store->adopt(new FileBuildStore("{$directory}/none.json")))->toBeFalse()
                ->and($store->adopt($file))->toBeTrue()
                ->and($store->read()->revoked)->toBe(['b1']);

            $file->update(static fn (BuildHistory $h): BuildHistory => $h->released('b3', NOW));

            expect($store->adopt($file))->toBeFalse()
                ->and($store->read()->current())->toBe('b2');
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    // ADR 0050 opens the application's SQLite connections at synchronous =
    // NORMAL, whose commits an OS crash can take back -- and a revocation
    // taken back is a revoked build served again. Synced at the commit,
    // whatever the connection was opened with, and left as it was after.
    it('commits a change to SQLite synced to the disk, on a connection opened at NORMAL', function () {
        onDriver('sqlite', function (\PDO $pdo, string $dsn, string $table): void {
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $store = installed($pdo, $table);
            $during = null;

            $store->update(static function (BuildHistory $history) use ($pdo, &$during): BuildHistory {
                $during = (int) $pdo->query('PRAGMA synchronous')->fetchColumn();

                return $history->revoking('b1');
            });

            expect($during)->toBe(2, 'FULL while the change commits')
                ->and((int) $pdo->query('PRAGMA synchronous')->fetchColumn())->toBe(1, 'NORMAL again after')
                ->and($store->read()->revoked)->toBe(['b1']);
        });
    });

    it('refuses a change on a connection with a transaction open, and a table name that is not one', function () {
        $pdo = new \PDO('sqlite::memory:');
        $store = installed($pdo, PdoBuildStore::TABLE);
        $pdo->beginTransaction();

        expect(fn () => $store->update(static fn (BuildHistory $h): BuildHistory => $h))->toThrow(\LogicException::class)
            ->and(fn () => new PdoBuildStore($pdo, 'x; DROP TABLE y'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => PdoBuildStore::schema('sqlsrv'))->toThrow(\InvalidArgumentException::class);
    });

    // Six processes each revoking their own build at the same instant: each
    // reads, changes and writes under the row's lock, so no revocation is
    // lost to another's write.
    it('loses no revocation to commands run at once on several servers', function (string $driver) {
        if (($why = missing($driver)) !== null) {
            $this->markTestSkipped($why);
        }

        onDriver($driver, function (\PDO $pdo, string $dsn, string $table) use ($driver): void {
            $store = installed($pdo, $table);
            $ids = array_map(static fn (int $i): string => "b{$i}", range(1, 7));
            $store->update(static function (BuildHistory $h) use ($ids): BuildHistory {
                foreach ($ids as $id) {
                    $h = $h->released($id, NOW);
                }

                return $h;
            });

            $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
            $script = sys_get_temp_dir() . '/tinvar-pdo-builds-revoke-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($script, <<<'PHP'
                <?php
                [, $autoload, $driver, $dsn, $table, $start, $build] = $argv;
                require $autoload;
                $pdo = new PDO($dsn);
                if ($driver === 'sqlite') {
                    $pdo->exec('PRAGMA busy_timeout = 10000');
                }
                $store = new Tinvar\Http\PdoBuildStore($pdo, $table);
                while (microtime(true) < (float) $start) {
                    usleep(1000);
                }
                $store->update(static fn (Tinvar\Http\BuildHistory $h) => $h->revoking($build));
                PHP);

            try {
                $start = (string) (microtime(true) + 1.5);
                $running = [];
                foreach (\array_slice($ids, 0, 6) as $id) {
                    $running[] = [proc_open(
                        [PHP_BINARY, $script, $autoload, $driver, $dsn, $table, $start, $id],
                        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pipes,
                    ) ?: throw new \RuntimeException('Could not start a revoking process.'), $pipes];
                }
                foreach ($running as [$process, $pipes]) {
                    // The CLI prints an uncaught exception on stdout: a
                    // revocation that failed says so on one or the other.
                    $err = stream_get_contents($pipes[2]);
                    $out = stream_get_contents($pipes[1]);
                    proc_close($process);
                    expect($err)->toBe('')->and($out)->toBe('');
                }

                $revoked = new PdoBuildStore(connect($driver, $dsn), $table)->read()->revoked;
                sort($revoked);

                expect($revoked)->toBe(\array_slice($ids, 0, 6));
            } finally {
                unlink($script);
            }
        });
    })->with(DRIVERS);
});

// A change that throws still puts the connection back: left at FULL, every
// later commit of the application's on it would pay for a sync it chose
// not to, and left there only by luck.
it('puts a NORMAL connection back after a change that fails', function () {
    onDriver('sqlite', function (\PDO $pdo, string $dsn, string $table): void {
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $store = installed($pdo, $table);

        expect(fn () => $store->update(static function (BuildHistory $history): BuildHistory {
            throw new \RuntimeException('the change failed');
        }))->toThrow(\RuntimeException::class)
            ->and((int) $pdo->query('PRAGMA synchronous')->fetchColumn())->toBe(1);
    });
});

// The process killed the moment update() returned: the revocation is the
// database's, for every other server to read. (An OS crash is FULL's to
// survive, which the test above it holds the commit to.)
it('keeps a revocation made by a process killed the moment it returned', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }
    onDriver('sqlite', function (\PDO $pdo, string $dsn, string $table): void {
        $store = installed($pdo, $table);
        $store->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

        $pid = pcntl_fork();
        if ($pid === 0) {
            $child = new \PDO($dsn);
            $child->exec('PRAGMA synchronous = NORMAL');
            new PdoBuildStore($child, $table)->update(static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));
            posix_kill(getmypid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);

        expect(pcntl_wifsignaled($status))->toBeTrue()
            ->and(new PdoBuildStore(new \PDO($dsn), $table)->read()->isRevoked('b1'))->toBeTrue();
    });
});
