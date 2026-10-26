<?php

declare(strict_types=1);

/**
 * On a plain query, #[Query(args:)] replaced the model arguments read from
 * __invoke while the parameter list still came from __invoke. An args naming
 * only `rack` left `site` unresolved: the gate was handed a string, and the
 * call ended in a TypeError. A plain query's args must now agree with its
 * __invoke exactly, or it is refused at registration.
 */

namespace Tinvar\Tests\Query\PlainQueryArgs;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Query;
use Tinvar\Auth\Gate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

/** Records the type of every subject it is handed. */
final class SubjectGate implements Gate
{
    /** @var array<string, string> */
    public array $subjects = [];

    public function allows(Authorize $check, array $arguments): bool
    {
        $this->subjects[(string) $check->argument] = get_debug_type($arguments[$check->argument] ?? null);

        return true;
    }
}

#[Query(name: 'plainArgs.partial', args: ['rack' => Rack::class])]
#[Authorize('view', 'rack'), Authorize('view', 'site')]
final class Partial
{
    public function __invoke(Rack $rack, Site $site): string
    {
        return $rack->name . '@' . $site->code;
    }
}

#[Query(name: 'plainArgs.mistyped', args: ['rack' => Rack::class, 'site' => 'string'])]
#[Authorize('view', 'rack'), Authorize('view', 'site')]
final class Mistyped
{
    public function __invoke(Rack $rack, Site $site): string
    {
        return $rack->name . '@' . $site->code;
    }
}

#[Query(name: 'plainArgs.extra', args: ['rack' => Rack::class, 'limit' => 'int'])]
#[Authorize('view', 'rack')]
final class Extra
{
    public function __invoke(Rack $rack): string
    {
        return $rack->name;
    }
}

/** Agrees with __invoke, in another order. */
#[Query(name: 'plainArgs.agreeing', args: ['site' => Site::class, 'rack' => Rack::class])]
#[Authorize('view', 'rack'), Authorize('view', 'site')]
final class Agreeing
{
    public function __invoke(Rack $rack, Site $site): string
    {
        return $rack->name . '@' . $site->code;
    }
}

it('refuses args that disagree with __invoke', function (string $class) {
    expect(fn () => (new QueryRegistry())->register($class))
        ->toThrow(\InvalidArgumentException::class, 'but its __invoke takes');
})->with([
    'naming only some parameters' => [Partial::class],
    'typing one differently' => [Mistyped::class],
    'naming one it does not take' => [Extra::class],
]);

it('resolves and authorizes every model argument of a query whose args agree', function () {
    $registry = new QueryRegistry();
    $registry->register(Agreeing::class);

    expect($registry->modelArgumentsFor('plainArgs.agreeing'))->toBe(['rack' => Rack::class, 'site' => Site::class]);

    $fixture = new Fixture(racks: 1, unitsPerRack: 0, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $gate = new SubjectGate();
    $runner = new QueryRunner($registry, $orm, $gate, debug: true);

    $results = $runner->run([[
        'id' => 1,
        'name' => 'plainArgs.agreeing',
        'args' => ['rack' => $fixture->ids['racks'][0], 'site' => $fixture->ids['sites'][0]],
    ]], ['plainArgs.agreeing']);

    expect($results[0])->toHaveKey('value')
        ->and($gate->subjects)->toBe(['rack' => Rack::class, 'site' => Site::class]);
});
