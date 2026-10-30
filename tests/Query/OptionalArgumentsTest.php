<?php

declare(strict_types=1);

/**
 * A query parameter is optional when it has a default or accepts null.
 *
 * Accepting null is not having a default: PHP still needs a value for
 * `?string $q`, and a call that left it out reached the body one argument
 * short. And null is what a renderer sends for an optional model it does
 * not have -- `undefined` encodes as nil -- so it means "absent", as leaving
 * the key out does.
 */

namespace Tinvar\Tests\Query\OptionalArguments;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Query\Fixtures\RackSummary;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/queries.php';

final readonly class Echoed extends Data
{
    public function __construct(public string $value) {}
}

#[Query(name: 'optional.search'), NoAuthorization(reason: 'argument binding is what is under test')]
final class Search
{
    public function __invoke(?string $q, int $limit = 10): Echoed
    {
        return new Echoed('q=' . var_export($q, true) . ' limit=' . $limit);
    }
}

/** An optional rack, with a check that decides the call without one. */
#[Query(name: 'optional.racks'), Authorize('viewAny', Rack::class), Authorize('view', 'rack')]
final class RackOrAll
{
    public function __invoke(?Rack $rack): Echoed
    {
        return new Echoed($rack === null ? 'all racks' : $rack->name);
    }
}

/** An optional rack whose only check names it. */
#[Query(name: 'optional.rackOnly'), Authorize('view', 'rack')]
final class RackOnly
{
    public function __invoke(?Rack $rack = null): Echoed
    {
        return new Echoed($rack === null ? 'all racks' : $rack->name);
    }
}

/** Denies one ability, allows the rest. */
final class DeniesViewAny implements Gate
{
    public function allows(Authorize $check, array $arguments): bool
    {
        return $check->ability !== 'viewAny';
    }
}

/**
 * @param array<string, mixed> $args
 * @return array{id: int, value?: mixed, error?: array{code: string, digest: string, message?: string}}
 */
function call(string $name, array $args, ?Gate $gate = null): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->registerAll([Search::class, RackOrAll::class, RackOnly::class]);
    $runner = new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), $gate ?? new AllowAllGate());

    $args = array_map(static fn (mixed $v): mixed => $v === 'RACK' ? $fixture->ids['racks'][0] : $v, $args);

    return $runner->run([['id' => 0, 'name' => $name, 'args' => $args]], [$name])[0];
}

it('passes null for a nullable parameter with no default that the call left out', function () {
    expect(call('optional.search', [])['value'] ?? null)->toBe(['value' => 'q=NULL limit=10'])
        ->and(call('optional.search', ['q' => 'sw'])['value'] ?? null)->toBe(['value' => "q='sw' limit=10"]);
});

it('treats null for an optional model as leaving it out', function (array $args) {
    expect(call('optional.racks', $args)['value'] ?? null)->toBe(['value' => 'all racks']);
})->with(['left out' => [[]], 'sent as null' => [['rack' => null]]]);

it('still resolves an optional model that the call names', function () {
    $result = call('optional.racks', ['rack' => 'RACK']);

    expect($result['value']['value'] ?? null)->toBeString()->not->toBe('all racks');
});

it('still asks the check that decides a call without the model', function (array $args) {
    expect(call('optional.racks', $args, new DeniesViewAny())['error']['code'] ?? null)
        ->toBe(QueryError::FORBIDDEN);
})->with(['left out' => [[]], 'sent as null' => [['rack' => null]]]);

it('refuses a call that sidesteps every check by sending null', function (array $args) {
    expect(call('optional.rackOnly', $args)['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN);
})->with(['left out' => [[]], 'sent as null' => [['rack' => null]]]);

it('still refuses null for a required model', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->register(RackSummary::class);
    $runner = new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate());

    $result = $runner->run([['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => null]]], ['racks.summary'])[0];

    expect($result['error']['code'] ?? null)->toBe(QueryError::BAD_ARGUMENTS);
});
