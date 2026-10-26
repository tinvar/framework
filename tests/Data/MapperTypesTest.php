<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\Types;

use Tinvar\Data\Data;
use Tinvar\Data\DataMappingException;
use Tinvar\Support\Lazy;
use Tinvar\Tests\Data\Elsewhere\ItemData;
use Tinvar\Tests\Data\Elsewhere\ItemData as Aliased;
use Tinvar\Tests\Data\Elsewhere;

require_once __DIR__ . '/Fixtures/elsewhere.php';

final readonly class Imported extends Data
{
    /**
     * @param list<ItemData>             $items   imported by use
     * @param array<string, Aliased>     $byKey   imported under an alias
     * @param list<Elsewhere\ItemData>   $partial through an imported namespace
     */
    public function __construct(public array $items, public array $byKey, public array $partial) {}
}

final readonly class Unknown extends Data
{
    /** @param list<NoSuchData> $items */
    public function __construct(public array $items) {}
}

final readonly class Scalars extends Data
{
    /**
     * @param list<int>               $counts
     * @param array<string, string>   $labels
     * @param list<\DateTimeImmutable> $when
     */
    public function __construct(public array $counts, public array $labels, public array $when) {}
}

final readonly class LazyShapes extends Data
{
    /**
     * @param Lazy<ItemData>             $one
     * @param Lazy<array<string, ItemData>> $many
     */
    public function __construct(public Lazy $one, public Lazy $many) {}
}

/** An iterable Data source: taken for a list by the old value-sniffing. */
final class IterableItem implements \IteratorAggregate
{
    public string $id = 'it';

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(['x', 'y']);
    }
}

// Names were resolved only fully qualified or in the class's own namespace,
// so an imported Data class went unmapped and failed later at the encoder.
it('resolves docblock names through use imports, aliases and imported namespaces', function () {
    $view = Imported::from([
        'items' => [['id' => 'a']],
        'byKey' => ['k' => ['id' => 'b']],
        'partial' => [['id' => 'c']],
    ]);

    expect($view->items)->toEqual([new ItemData('a')])
        ->and($view->byKey)->toEqual(['k' => new ItemData('b')])
        ->and($view->partial)->toEqual([new ItemData('c')]);
});

it('refuses a docblock name that is not a class, when the plan is built', function () {
    expect(fn () => Unknown::from(['items' => []]))
        ->toThrow(DataMappingException::class, "names 'NoSuchData' in its docblock, which is not a class");
});

it('passes lists of scalars and of non-Data classes through as they are', function () {
    $when = new \DateTimeImmutable('2026-10-23');
    $view = Scalars::from(['counts' => [1, 2], 'labels' => ['a' => 'b'], 'when' => [$when]]);

    expect($view->counts)->toBe([1, 2])->and($view->labels)->toBe(['a' => 'b'])->and($view->when)->toBe([$when]);
});

// The list-or-one decision came from the value: an iterable source was
// taken for a list, and a keyed array of Data for one object.
it('decides a Lazy field is one or many from its declared type', function () {
    $view = LazyShapes::from(['one' => new IterableItem(), 'many' => ['k' => ['id' => 'm']]]);

    expect($view->one->resolve())->toEqual(new ItemData('it'))
        ->and($view->many->resolve())->toEqual(['k' => new ItemData('m')]);
});

it('says when a Data field is handed a scalar', function () {
    expect(fn () => Imported::from(['items' => ['nope'], 'byKey' => [], 'partial' => []]))
        ->toThrow(DataMappingException::class, 'is a Data field, but its source is string');
});
