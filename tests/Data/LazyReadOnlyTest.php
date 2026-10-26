<?php

declare(strict_types=1);

/**
 * Work a loader or query defers until encoding.
 *
 * A Lazy field is resolved by the encoder, after the body that made it has
 * returned and its read-only transaction has ended. A Lazy::of() closure, or
 * a Mapper lazy over an array source, carried no scope with it, so a save()
 * inside one went through on a GET -- and on a hover prefetch.
 */

namespace Tinvar\Tests\Data\LazyReadOnly;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

/** The Orm the loaders and queries below write through, as an app's container would hand it to them. */
final class Db
{
    public static Orm $orm;
}

final readonly class RackViewData extends Data
{
    /** @param Lazy<string> $views */
    public function __construct(public string $name, public Lazy $views) {}
}

final readonly class CounterData extends Data
{
    public function __construct(public string $views) {}
}

final readonly class MappedViewData extends Data
{
    /** @param Lazy<CounterData> $counter */
    public function __construct(public string $name, public Lazy $counter) {}
}

/** Renames the rack it is handed, then reports the count: a write deferred past the body. */
function countingView(Rack $rack): \Closure
{
    return static function () use ($rack): string {
        $rack->name = 'WRITTEN DURING GET';
        Db::$orm->save($rack);

        return 'counted';
    };
}

#[Page('/racks/{rack}', view: 'racks/show', name: 'racks.show'), Authorize('view', 'rack')]
final class ShowRack
{
    public function __invoke(Rack $rack): RackViewData
    {
        return new RackViewData($rack->name, Lazy::of(countingView($rack)));
    }
}

/** Mapped from by CounterData: reading $views is the write. */
final class ViewCounter
{
    public string $views {
        get => (countingView($this->rack))();
    }

    public function __construct(private readonly Rack $rack) {}
}

#[Page('/racks/{rack}/mapped', view: 'racks/mapped', name: 'racks.mapped'), Authorize('view', 'rack')]
final class ShowMappedRack
{
    public function __invoke(Rack $rack): MappedViewData
    {
        // An array source: the mapper has no model to take a scope from, and
        // reads 'counter' -- running the hook -- only when the field resolves.
        return MappedViewData::from(['name' => $rack->name, 'counter' => new ViewCounter($rack)]);
    }
}

#[Query(name: 'racks.views'), NoAuthorization(reason: 'fixture: exercises read-only encoding, not access control')]
final class RackViews
{
    public function __invoke(Rack $rack): RackViewData
    {
        return new RackViewData($rack->name, Lazy::of(countingView($rack)));
    }
}

/** Returns a Lazy made before its body ran, outside any read-only scope. */
#[Query(name: 'racks.premade'), NoAuthorization(reason: 'fixture: exercises read-only encoding, not access control')]
final class PremadeViews
{
    private readonly Lazy $views;

    public function __construct()
    {
        $this->views = Lazy::of(static function (): string {
            $rack = Db::$orm->all(Rack::class)[0];

            return (countingView($rack))();
        });
    }

    public function __invoke(Rack $rack): RackViewData
    {
        return new RackViewData($rack->name, $this->views);
    }
}

/** @return array{0: Fixture, 1: Orm, 2: string} */
function fixture(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    Db::$orm = new Orm($fixture->connection, new IdentityMap('test'));

    return [$fixture, Db::$orm, $fixture->ids['racks'][0]];
}

function nameInDatabase(Fixture $fixture): string
{
    return (string) $fixture->connection->select('SELECT name FROM racks')[0]['name'];
}

function load(Orm $orm, string $path): object
{
    $routes = new RouteRegistry();
    $routes->registerAll([ShowRack::class, ShowMappedRack::class]);
    $chain = $routes->match($path) ?? throw new \LogicException("no route for {$path}");

    return (new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()))
        ->run($chain, new Request('GET', $path))
        ->page ?? throw new \LogicException('no page data');
}

it('refuses a write in a loader Lazy resolved when the page is encoded', function (string $suffix) {
    [$fixture, $orm, $id] = fixture();
    $page = load($orm, "/racks/{$id}{$suffix}");

    expect(fn () => (new Encoder())->encode($page))->toThrow(ReadOnlyViolation::class)
        ->and(nameInDatabase($fixture))->toBe('R-01');
})->with([
    'Lazy::of' => [''],
    'a mapped array source' => ['/mapped'],
]);

it('refuses a write in a query result Lazy, and fails only that call', function (string $name) {
    [$fixture, $orm, $id] = fixture();
    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->registerAll([RackViews::class, PremadeViews::class]);

    $results = (new QueryRunner($registry, $orm, new AllowAllGate()))->run(
        [['id' => 0, 'name' => $name, 'args' => ['rack' => $id]]],
        [$name],
    );

    expect($results[0]['error']['code'])->toBe(QueryError::FAILED)
        ->and(nameInDatabase($fixture))->toBe('R-01');
})->with([
    'made in the body' => ['racks.views'],
    'made before the body ran' => ['racks.premade'],
]);

it('refuses it for a Lazy made in a read-only fiber and resolved outside it', function () {
    [$fixture, $orm, $id] = fixture();
    $rack = $orm->find(Rack::class, $id) ?? throw new \LogicException('no rack');

    $fiber = new \Fiber(static fn (): Lazy => $orm->readOnly(static fn (): Lazy => Lazy::of(countingView($rack))));
    $fiber->start();
    $lazy = $fiber->getReturn();

    expect(fn () => $lazy->resolve())->toThrow(ReadOnlyViolation::class)
        ->and(nameInDatabase($fixture))->toBe('R-01');
});

it('leaves a Lazy made outside any read-only scope free to write', function () {
    [$fixture, $orm, $id] = fixture();
    $rack = $orm->find(Rack::class, $id) ?? throw new \LogicException('no rack');

    $lazy = Lazy::of(countingView($rack));
    $orm->readOnly(static fn (): null => null);

    expect($lazy->resolve())->toBe('counted')
        ->and(nameInDatabase($fixture))->toBe('WRITTEN DURING GET');
});
