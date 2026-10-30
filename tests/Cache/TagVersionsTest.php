<?php

declare(strict_types=1);

/**
 * The tag versions once-shared holds are bound to (ADR 0074): kept where
 * every worker reads them, bumped to a version nobody can guess, stamped
 * per tenant -- a write in one never moves another's -- and failing closed.
 */

namespace Tinvar\Tests\Cache\TagVersions;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Cache\ArrayTagVersions;
use Tinvar\Cache\PdoTagVersions;
use Tinvar\Cache\TagNamespace;
use Tinvar\Cache\TagVersions;
use Tinvar\Data\SharedTags;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Actions/Fixtures/actions.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/**
 * Runs $test with a store on an empty database of the driver's, and a way
 * to open a second store on it -- another worker.
 *
 * @param \Closure(PdoTagVersions, \Closure(): PdoTagVersions, \PDO): void $test
 */
function onDriver(string $driver, \Closure $test, ?\Closure $clock = null): void
{
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-tag-versions-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $dsn = "sqlite:{$directory}/tags.sqlite";
        $open = static function () use ($dsn): \PDO {
            $pdo = new \PDO($dsn);
            $pdo->exec('PRAGMA busy_timeout = 10000');

            return $pdo;
        };

        try {
            $pdo = $open();
            $store = new PdoTagVersions($pdo, clock: $clock);
            $store->install();
            $test($store, static fn (): PdoTagVersions => new PdoTagVersions($open(), clock: $clock), $pdo);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }

        return;
    }

    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $test, $clock): void {
        $table = "{$schema}." . PdoTagVersions::TABLE;
        $store = new PdoTagVersions($pdo, $table, $clock);
        $store->install();
        $test($store, static fn (): PdoTagVersions => new PdoTagVersions(Databases::another($driver), $table, $clock), $pdo);
    });
}

function skipWithout(string $driver): void
{
    $missing = match ($driver) {
        'pgsql' => Databases::postgresMissing(),
        'mysql' => Databases::mysqlMissing(),
        default => null,
    };
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
}

describe('a PDO tag version store', function () {
    it('reads 0 for a tag never bumped, and what another worker bumped', function (string $driver) {
        skipWithout($driver);
        onDriver($driver, function (PdoTagVersions $store, \Closure $another): void {
            expect($store->versions(["\0orders", "\0notes"]))->toBe(["\0orders" => 0, "\0notes" => 0]);

            $another()->bump(["\0orders"]);
            $first = $store->versions(["\0orders", "\0notes"]);
            expect($first["\0orders"])->toBeGreaterThan(0)
                ->and($first["\0notes"])->toBe(0);

            $store->bump(["\0orders"]);
            expect($another()->versions(["\0orders"])["\0orders"])->not->toBe($first["\0orders"]);
        });
    })->with(['sqlite', 'pgsql', 'mysql']);

    it('keeps no tag nor tenant readable in its table', function () {
        onDriver('sqlite', function (PdoTagVersions $store, \Closure $another, \PDO $pdo): void {
            $store->bump(TagNamespace::read(['customer:42'], 'acme', false));

            $rows = $pdo->query('SELECT tag FROM ' . PdoTagVersions::TABLE)?->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            expect($rows)->toHaveCount(1)
                ->and($rows[0])->toMatch('/^[0-9a-f]{64}$/');
        });
    });

    it('lets a row go only once every hold minted before its last bump has expired', function () {
        $now = 1_800_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        onDriver('sqlite', function (PdoTagVersions $store) use (&$now): void {
            $store->bump(["\0old"]);
            $now += PdoTagVersions::KEEP_SECONDS + 1;
            // Bump until a sweep runs.
            for ($i = 1; $i < PdoTagVersions::SWEEP_EVERY; $i++) {
                $store->bump(["\0new"]);
            }

            expect($store->versions(["\0old"]))->toBe(["\0old" => 0])
                ->and($store->versions(["\0new"])["\0new"])->toBeGreaterThan(0);
        }, $clock);
    });

    it('refuses a connection inside a transaction, where a bump would roll back with the write', function () {
        onDriver('sqlite', function (PdoTagVersions $store, \Closure $another, \PDO $pdo): void {
            $pdo->beginTransaction();

            expect(fn () => $store->bump(["\0orders"]))->toThrow(\LogicException::class, 'transaction open');
            $pdo->rollBack();
        });
    });

    it('refuses a table name it would not put in SQL', function () {
        expect(fn () => new PdoTagVersions(new \PDO('sqlite::memory:'), 'tags; DROP TABLE x'))
            ->toThrow(\InvalidArgumentException::class);
    });
});

describe('the shared tags', function () {
    /** @return array{SharedTags, Orm, TagVersions} */
    function sharedTags(?TagVersions $versions = null, ?Tenancy $tenancy = null): array
    {
        $orm = new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test'), $tenancy ?? Tenancy::flat());
        $versions ??= new ArrayTagVersions();

        return [new SharedTags($versions, $orm, static function (): void {}), $orm, $versions];
    }

    it('stamps a key\'s tags as they stand, and moves the stamp when one is bumped', function () {
        [$tags] = sharedTags();
        $before = $tags->stamp(['orders', 'notes'], 'acme');

        expect($tags->stamp(['orders', 'notes'], 'acme'))->toBe($before)
            ->and($tags->stamp([], 'acme'))->toBe('');

        $tags->invalidate(['orders'], 'acme');

        expect($tags->stamp(['orders', 'notes'], 'acme'))->not->toBe($before);
    });

    it('never moves another tenant\'s stamp', function () {
        [$tags] = sharedTags();
        $acme = $tags->stamp(['orders'], 'acme');

        $tags->invalidate(['orders'], 'globex');

        expect($tags->stamp(['orders'], 'acme'))->toBe($acme);
    });

    it('moves a parent\'s stamp for a write made as a tenant below it', function () {
        [$tags] = sharedTags(tenancy: Tenancy::tree(['acme-east' => 'acme']));
        $parent = $tags->stamp(['orders'], 'acme');
        $sibling = $tags->stamp(['orders'], 'globex');

        $tags->invalidate(['orders'], 'acme-east');

        expect($tags->stamp(['orders'], 'acme'))->not->toBe($parent)
            ->and($tags->stamp(['orders'], 'globex'))->toBe($sibling);
    });

    it('has no stamp while the store cannot say, and throws a bump it cannot make', function () {
        $down = new class implements TagVersions {
            public function versions(array $tags): array
            {
                throw new \RuntimeException('down');
            }

            public function bump(array $tags): void
            {
                throw new \RuntimeException('down');
            }
        };
        [$tags] = sharedTags($down);

        expect($tags->stamp(['orders'], 'acme'))->toBeNull()
            ->and(fn () => $tags->invalidate(['orders'], 'acme'))->toThrow(\RuntimeException::class, 'down');
    });

    it('has no stamp from a store that left a tag out', function () {
        $partial = new class implements TagVersions {
            public function versions(array $tags): array
            {
                return [];
            }

            public function bump(array $tags): void {}
        };
        [$tags] = sharedTags($partial);

        expect($tags->stamp(['orders'], ''))->toBeNull();
    });

    it('is bumped by an action\'s invalidated tags, so every browser\'s hold goes, not only the caller\'s', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $tags = new SharedTags(new ArrayTagVersions(), $orm);
        $registry = new ActionRegistry();
        $registry->register(A\RackActions::class);
        $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm), sharedTags: $tags);
        $rack = $fixture->ids['racks'][0];
        $before = $tags->stamp(["rack:{$rack}"], '');

        $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => 12]);

        expect($tags->stamp(["rack:{$rack}"], ''))->not->toBe($before);
    });

    it('bumps again once the write commits, so a hold minted in between is not believed', function () {
        [$tags, $orm] = sharedTags();
        $seen = [];

        $orm->transaction(function () use ($tags, &$seen): void {
            $tags->invalidate(['orders'], 'acme');
            $seen[] = $tags->stamp(['orders'], 'acme');
        });
        $seen[] = $tags->stamp(['orders'], 'acme');

        expect($seen[0])->not->toBe($seen[1]);
    });
});
