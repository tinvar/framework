<?php

declare(strict_types=1);

/**
 * An action's invalidation, against a query cache shared between workers
 * (ApcuStore) or servers (PdoStore): another worker reading around the
 * action's commit must not keep an answer from before it.
 *
 * Two stores on one cache stand in for two workers: the action runs with
 * the first, and the second is another request, served elsewhere, that
 * misses, reads the rows, and puts what it read.
 */

namespace Tinvar\Tests\Actions\SharedCacheInvalidation;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Cache\PdoStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Cache\Store;
use Tinvar\Data\Encoder;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Actions\Fixtures as A;
use Tinvar\Tests\Cache\Fixtures\FakeApcu;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/actions.php';
require_once __DIR__ . '/../Cache/Fixtures/apcu.php';

/**
 * Two workers' stores on one shared cache.
 *
 * @return array{Store, Store}
 */
function workers(string $kind): array
{
    if ($kind === 'apcu') {
        $apcu = new FakeApcu();

        return [$apcu->store(), $apcu->store()];
    }

    $directory = sys_get_temp_dir() . '/tinvar-shared-cache-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    });

    $first = new PdoStore(new \PDO("sqlite:{$directory}/cache.sqlite"));
    $first->install();

    return [$first, new PdoStore(new \PDO("sqlite:{$directory}/cache.sqlite"))];
}

/**
 * An action runner whose query cache is kept in $store, and the rack its
 * action moves a unit in.
 *
 * @return array{ActionRunner, Orm, string}
 */
function runner(Store $store): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(A\RackActions::class);

    return [
        new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm), new Encoder(), new QueryCache($store, new AuthContext())),
        $orm,
        $fixture->ids['racks'][0],
    ];
}

/** A cached query tagged with its rack, as RackActions::moveUnit() revalidates. */
function declaration(): QueryAttribute
{
    return new QueryAttribute(cache: '5m', tags: static function (string $rack): array {
        return ['rack:' . $rack];
    });
}

const KINDS = ['apcu' => ['apcu'], 'pdo' => ['pdo']];

// A forget made inside the transaction is followed by a read that cannot see
// the writes yet. Forgotten only then, that answer was kept for its TTL.
it('drops what another worker cached between the action\'s forget and its commit', function (string $kind) {
    [$mine, $theirs] = workers($kind);
    [$runner, $orm, $rack] = runner($mine);
    $other = new QueryCache($theirs, new AuthContext());
    $arguments = ['rack' => $rack];
    $tenant = $orm->tenant();

    $orm->transaction(function () use ($runner, $other, $rack, $arguments, $tenant): void {
        $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => 12]);

        expect($other->get('racks.cached', declaration(), $arguments, $tenant)['hit'])->toBeFalse('forgotten inside the transaction');
        $other->put('racks.cached', declaration(), $arguments, $tenant, ['position' => 'before the move']);
        expect($other->get('racks.cached', declaration(), $arguments, $tenant)['hit'])->toBeTrue('kept, until the commit');
    });

    expect($other->get('racks.cached', declaration(), $arguments, $tenant)['hit'])->toBeFalse('dropped once the writes committed');
})->with(KINDS);

it('forgets nothing more when the action\'s transaction rolls back', function (string $kind) {
    [$mine, $theirs] = workers($kind);
    [$runner, $orm, $rack] = runner($mine);
    $other = new QueryCache($theirs, new AuthContext());
    $arguments = ['rack' => $rack];
    $tenant = $orm->tenant();

    try {
        $orm->transaction(function () use ($runner, $other, $rack, $arguments, $tenant): never {
            $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => 12]);
            $other->get('racks.cached', declaration(), $arguments, $tenant);
            $other->put('racks.cached', declaration(), $arguments, $tenant, ['position' => 'as it still is']);

            throw new \RuntimeException('rolled back');
        });
    } catch (\RuntimeException) {
    }

    // Nothing changed, so what was read stands.
    expect($other->get('racks.cached', declaration(), $arguments, $tenant))
        ->toBe(['hit' => true, 'value' => ['position' => 'as it still is']]);
})->with(KINDS);

// No transaction around the action: another worker misses before the write,
// and puts what it read after the action has committed and forgotten.
it('keeps nothing another worker read before the action committed and put after it', function (string $kind) {
    [$mine, $theirs] = workers($kind);
    [$runner, $orm, $rack] = runner($mine);
    $other = new QueryCache($theirs, new AuthContext());
    $arguments = ['rack' => $rack];
    $tenant = $orm->tenant();

    expect($other->get('racks.cached', declaration(), $arguments, $tenant)['hit'])->toBeFalse();
    $runner->run('RackActions.moveUnit', ['rack' => $rack, 'position' => 12]);
    $other->put('racks.cached', declaration(), $arguments, $tenant, ['position' => 'before the move']);

    expect($other->get('racks.cached', declaration(), $arguments, $tenant)['hit'])->toBeFalse();

    // Its next miss reads after the write, and is kept.
    $other->put('racks.cached', declaration(), $arguments, $tenant, ['position' => 12]);
    expect($other->get('racks.cached', declaration(), $arguments, $tenant))
        ->toBe(['hit' => true, 'value' => ['position' => 12]]);
})->with(KINDS);
