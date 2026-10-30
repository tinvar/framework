<?php

declare(strict_types=1);

namespace Tinvar\Tests\Query\Surface;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\Gate;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\Surface\InertQuery;
use Tinvar\Tests\Query\Fixtures\Surface\MistaggedCachedQuery;
use Tinvar\Tests\Query\Fixtures\Surface\NotAQuery;
use Tinvar\Tests\Query\Fixtures\Surface\OptionalRackSummary;
use Tinvar\Tests\Query\Fixtures\Surface\ThrowingBatch;
use Tinvar\Tests\Query\Fixtures\Surface\UntaggedCachedQuery;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';
require_once __DIR__ . '/Fixtures/surface.php';

/**
 * @param list<class-string> $classes
 * @param (\Closure(class-string): object)|null $resolver
 */
function surfaceRunner(
    Fixture $fixture,
    array $classes,
    ?Gate $gate = null,
    ?\Closure $resolver = null,
    ?QueryCache $cache = null,
): QueryRunner {
    $registry = new QueryRegistry($resolver);
    $registry->registerAll($classes);

    return new QueryRunner(
        $registry,
        new Orm($fixture->connection, new IdentityMap('test')),
        $gate ?? new AllowAllGate(),
        cache: $cache,
    );
}

/** @param array<string, mixed> $args */
function surfaceCall(string $name, array $args = [], int $id = 0): array
{
    return ['id' => $id, 'name' => $name, 'args' => $args];
}

describe('the status a query error means over HTTP', function () {
    it('maps each code to the status the client acts on', function (string $code, int $status) {
        expect(QueryError::statusFor($code))->toBe($status);
    })->with([
        'a denial asks the client to re-authenticate' => [QueryError::FORBIDDEN, 403],
        'absent and out of scope read alike' => [QueryError::NOT_FOUND, 404],
        'a name off the allowlist' => [QueryError::NOT_ALLOWED, 404],
        'an unregistered name' => [QueryError::UNKNOWN, 404],
        'bad input is the caller\'s to fix' => [QueryError::BAD_ARGUMENTS, 422],
        'a failing body' => [QueryError::FAILED, 500],
        'a result the data boundary refused' => [QueryError::BAD_RESULT, 500],
        'a declaration bug' => [QueryError::MISCONFIGURED, 500],
        'an unclassified code' => ['something_else', 422],
    ]);
});

describe('registration', function () {
    it('refuses a class that is not declared as a query', function () {
        expect(fn () => (new QueryRegistry())->register(NotAQuery::class))
            ->toThrow(\InvalidArgumentException::class, 'has no #[Query] attribute');
    });

    it('refuses a query class with no body', function () {
        expect(fn () => (new QueryRegistry())->register(InertQuery::class))
            ->toThrow(\InvalidArgumentException::class, 'is a #[Query] with no body');
    });
});

describe('an optional model argument', function () {
    // Under a deny gate this used to answer 'all racks': the only check named
    // the rack, so leaving it out left nothing to ask. The fixture now
    // carries a class check, and that is what decides the call.
    it('skips the check on it when the call leaves it out', function () {
        $gate = new AllowAllGate();
        $runner = surfaceRunner(new Fixture(racks: 1, unitsPerRack: 1), [OptionalRackSummary::class], $gate);

        $results = $runner->run([surfaceCall('surface.optionalRack')], ['surface.optionalRack']);

        expect($results[0])->not->toHaveKey('error')
            ->and($results[0]['value']['name'])->toBe('all racks')
            ->and(array_column($gate->checks, 'ability'))->toBe(['viewAny']);
    });

    it('still runs the check when the call names one', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $gate = new AllowAllGate();
        $runner = surfaceRunner($fixture, [OptionalRackSummary::class], $gate);

        $runner->run(
            [surfaceCall('surface.optionalRack', ['rack' => $fixture->ids['racks'][0]])],
            ['surface.optionalRack'],
        );

        expect(array_column($gate->checks, 'ability'))->toBe(['viewAny', 'view']);
    });

    it('lets a deny gate stop it either way', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $runner = surfaceRunner($fixture, [OptionalRackSummary::class], new DenyGate());

        $results = $runner->run([
            surfaceCall('surface.optionalRack', [], 0),
            surfaceCall('surface.optionalRack', ['rack' => $fixture->ids['racks'][0]], 1),
        ], ['surface.optionalRack']);

        expect(array_column(array_column($results, 'error'), 'code'))
            ->toBe([QueryError::FORBIDDEN, QueryError::FORBIDDEN]);
    });
});

describe('a query the runner cannot call', function () {
    it('refuses a query whose resolver hands back something it cannot call', function () {
        $runner = surfaceRunner(
            new Fixture(racks: 1, unitsPerRack: 1),
            [OptionalRackSummary::class],
            resolver: static fn (string $class): object => new \stdClass(),
        );

        expect(fn () => $runner->run([surfaceCall('surface.optionalRack')], ['surface.optionalRack']))
            ->toThrow(\LogicException::class, "Query 'surface.optionalRack' is not invocable");
    });

    it('refuses a batch query whose resolver hands back something else', function () {
        $runner = surfaceRunner(
            new Fixture(racks: 1, unitsPerRack: 1),
            [ThrowingBatch::class],
            resolver: static fn (string $class): object => new \stdClass(),
        );

        expect(fn () => $runner->run([surfaceCall('surface.batchBoom', ['a' => 1])], ['surface.batchBoom']))
            ->toThrow(\LogicException::class, 'registered as a batch but its instance is not a BatchQuery');
    });
});

describe('a batch query that throws', function () {
    it('fails every call in the batch with a classified error', function () {
        $runner = surfaceRunner(new Fixture(racks: 1, unitsPerRack: 1), [ThrowingBatch::class]);

        $results = $runner->run([
            surfaceCall('surface.batchBoom', ['a' => 0], 0),
            surfaceCall('surface.batchBoom', ['a' => 1], 1),
        ], ['surface.batchBoom']);

        expect(array_column($results, 'error'))->toHaveCount(2)
            ->and($results[0]['error']['code'])->toBe(QueryError::FAILED)
            ->and($results[1]['error']['code'])->toBe(QueryError::FAILED)
            ->and($results[0]['error'])->not->toHaveKey('message');
    });
});

describe('cache tags', function () {
    it('records no tags for a cached query that declares none, and still caches it', function () {
        UntaggedCachedQuery::$runs = 0;
        $cache = new QueryCache(new ArrayStore(), new AuthContext());
        $runner = surfaceRunner(new Fixture(racks: 1, unitsPerRack: 1), [UntaggedCachedQuery::class], cache: $cache);

        $first = $runner->run([surfaceCall('surface.untagged')], ['surface.untagged']);
        $second = $runner->run([surfaceCall('surface.untagged')], ['surface.untagged']);

        expect($first[0]['value'])->toBe($second[0]['value'])
            ->and(UntaggedCachedQuery::$runs)->toBe(1)
            ->and($runner->tagsRead())->toBe([]);
    });

    // A mistyped tag closure is a caching bug. It must not fail the render;
    // QueryCache records it and declines to cache that one result.
    it('answers the call when the tag closure returns something other than strings', function () {
        $cache = new QueryCache(new ArrayStore(), new AuthContext());
        $runner = surfaceRunner(new Fixture(racks: 1, unitsPerRack: 1), [MistaggedCachedQuery::class], cache: $cache);

        $results = $runner->run([surfaceCall('surface.mistagged')], ['surface.mistagged']);

        expect($results[0]['value']['name'])->toBe('mistagged')
            ->and($runner->tagsRead())->toBe([])
            ->and($cache->failures())->toHaveCount(1)
            ->and($cache->failures()[0]->getMessage())->toContain('must return strings');
    });
});
