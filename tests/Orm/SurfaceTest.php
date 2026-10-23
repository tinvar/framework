<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\Surface;

use Tinvar\Attributes\BelongsTo;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Table;
use Tinvar\Orm\BatchScheduler;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Unit;

require_once __DIR__ . '/Fixtures/models.php';
require_once __DIR__ . '/Fixtures/schema.php';

final class NoTable extends Model
{
    #[Id] public private(set) string $id;
}

#[Table('no_id')]
final class NoId extends Model
{
    #[Column] public string $name;
}

#[Table('untyped')]
final class UntypedRelation extends Model
{
    #[Id] public private(set) string $id;

    #[BelongsTo]
    public mixed $owner;
}

#[Table('odd')]
final class OddColumn extends Model
{
    #[Id] public private(set) string $id;

    #[Column] public \stdClass $blob;
}

function unit(string $name): Unit
{
    $unit = new Unit();
    $unit->position = 1;
    $unit->name = $name;

    return $unit;
}

describe('relation collections', function () {
    it('reads like a list', function () {
        $collection = Collection::of([unit('a'), unit('b'), unit('c')]);

        expect($collection->count())->toBe(3)
            ->and($collection->first()?->name)->toBe('a')
            ->and($collection->all())->toHaveCount(3)
            ->and($collection->map(fn (Unit $u): string => $u->name))->toBe(['a', 'b', 'c'])
            ->and($collection->filter(fn (Unit $u): bool => $u->name !== 'b'))->toHaveCount(2)
            ->and(array_map(fn (Unit $u): string => $u->name, iterator_to_array($collection)))->toBe(['a', 'b', 'c'])
            ->and(isset($collection[1]))->toBeTrue()
            ->and($collection[1]?->name)->toBe('b')
            ->and($collection[9])->toBeNull()
            ->and(Collection::of([])->first())->toBeNull();
    });

    // Lazy until read, so a relation nobody touches costs no statement.
    it('loads its contents once, on first read', function () {
        $loads = 0;
        $collection = Collection::lazy(function () use (&$loads): array {
            $loads++;

            return [unit('a')];
        });

        expect($collection->isLoaded())->toBeFalse();
        $collection->count();
        $collection->all();

        expect($collection->isLoaded())->toBeTrue()->and($loads)->toBe(1);
    });

    it('is read-only', function (\Closure $write) {
        expect(fn () => $write(Collection::of([unit('a')])))
            ->toThrow(\LogicException::class, 'Relation collections are read-only');
    })->with([
        'set' => [fn (Collection $c) => $c[0] = unit('b')],
        'append' => [fn (Collection $c) => $c[] = unit('b')],
        'unset' => [function (Collection $c): void {
            unset($c[0]);
        }],
    ]);
});

describe('model metadata', function () {
    it('refuses a model it cannot map', function (string $class, string $message) {
        expect(fn () => Metadata::for($class))->toThrow(MappingException::class, $message);
    })->with([
        'no table' => [NoTable::class, 'has no #[Table] attribute'],
        'no id' => [NoId::class, 'has no #[Id] property'],
        'untyped relation' => [UntypedRelation::class, 'has #[BelongsTo] but no class type'],
        'unsupported column' => [OddColumn::class, 'has unsupported column type stdClass'],
    ]);

    // A column name reaches SQL as an identifier, so it is checked against
    // the model's own columns, not merely quoted.
    it('refuses an order-by column the model does not have, or a malformed one', function (string $column, string $message) {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect(fn () => $orm->all(Rack::class, $column))->toThrow(MappingException::class, $message);
    })->with([
        'unknown' => ['password', 'password'],
        'malformed' => ['name; DROP TABLE racks', 'name; DROP TABLE racks'],
    ]);
});

// A fiber that suspends outside the scheduler can never be resumed; the
// render would hang until its deadline instead of failing.
it('reports a render whose fibers wait on nothing it can load', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $scheduler = new BatchScheduler(new Orm($fixture->connection, new IdentityMap('test')));

    expect(fn () => $scheduler->run(['stuck' => static fn () => \Fiber::suspend()]))
        ->toThrow(\LogicException::class, 'Render stalled: 1 fiber(s) suspended with no pending relation load.');
});
