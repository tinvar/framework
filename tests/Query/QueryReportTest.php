<?php

declare(strict_types=1);

/**
 * A query that throws is reported under the digest its error record carries.
 *
 * The record keeps the exception's text away from the renderer. With nothing
 * else, it kept it from everyone: a deferred fragment whose query failed was
 * rescued, showed the visitor a reference, and no log line had it.
 */

namespace Tinvar\Tests\Query\Report;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\Surface\ThrowingBatch;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';
require_once __DIR__ . '/Fixtures/surface.php';

#[Query(name: 'report.boom'), NoAuthorization(reason: 'fixture: exercises a failing body, not access control')]
final class Boom
{
    public function __invoke(): bool
    {
        throw new \RuntimeException('carrier timed out');
    }
}

/**
 * @param list<class-string> $classes
 * @param list<array{\Throwable, ?string}> $reported
 */
function runner(array $classes, array &$reported): QueryRunner
{
    $registry = new QueryRegistry();
    $registry->registerAll($classes);

    return new QueryRunner(
        $registry,
        new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test')),
        new AllowAllGate(),
        report: static function (\Throwable $e, ?string $digest) use (&$reported): string {
            $reported[] = [$e, $digest];

            return (string) $digest;
        },
    );
}

it('reports a body that throws under the digest the renderer is sent', function () {
    $reported = [];

    [$result] = runner([Boom::class], $reported)->run([['id' => 0, 'name' => 'report.boom', 'args' => []]], ['report.boom']);

    expect($result['error']['code'])->toBe(QueryError::FAILED)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0][0]->getMessage())->toBe('carrier timed out')
        ->and($reported[0][1])->toBe($result['error']['digest']);
});

// One throw fails every call made to the batch; it is one failure to log.
it('reports a batch that throws once, however many calls it fails', function () {
    $reported = [];

    $results = runner([ThrowingBatch::class], $reported)->run([
        ['id' => 0, 'name' => 'surface.batchBoom', 'args' => ['a' => 0]],
        ['id' => 1, 'name' => 'surface.batchBoom', 'args' => ['a' => 1]],
    ], ['surface.batchBoom']);

    expect($reported)->toHaveCount(1)
        ->and($reported[0][1])->toBe($results[0]['error']['digest'])
        ->and($results[1]['error']['digest'])->toBe($results[0]['error']['digest']);
});

// A refusal is an answer, not a failure: nothing threw, so nothing is logged.
it('reports nothing for a call the allowlist refuses', function () {
    $reported = [];

    [$result] = runner([Boom::class], $reported)->run([['id' => 0, 'name' => 'report.boom', 'args' => []]], []);

    expect($result['error']['code'])->toBe(QueryError::NOT_ALLOWED)
        ->and($reported)->toBe([]);
});
