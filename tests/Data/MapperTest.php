<?php

declare(strict_types=1);

/**
 * Data::from() and Data::collect(): "same-named properties map
 * automatically", #[From] names one that differs, and #[MapsFrom] fixes the
 * source class. Before these existed, #[From] and #[MapsFrom] were declared
 * and read by nothing.
 */

namespace Tinvar\Tests\Data\Mapping;

use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Data\Data;
use Tinvar\Data\DataMappingException;
use Tinvar\Data\Encoder;
use Tinvar\Data\Lazy;

final class UnitModel
{
    public function __construct(public string $id, public int $position, public string $secret = 'hash') {}
}

final class RackModel
{
    public int $reads = 0;

    /** @param list<UnitModel> $units */
    public function __construct(public string $id, public string $name, private array $units, public ?UnitModel $primary = null) {}

    public array $equipment {
        get {
            $this->reads++;

            return $this->units;
        }
    }
}

#[MapsFrom(UnitModel::class)]
final readonly class UnitView extends Data
{
    public function __construct(public string $id, public int $position) {}
}

#[MapsFrom(RackModel::class)]
final readonly class RackView extends Data
{
    /**
     * @param list<UnitView> $units
     */
    public function __construct(
        public string $id,
        #[From('name')]
        public string $label,
        #[From('equipment')]
        public array $units,
        public ?UnitView $primary,
        public string $note = 'none',
    ) {}
}

final readonly class LazyRackView extends Data
{
    /**
     * @param Lazy<list<UnitView>> $units
     * @param Lazy<UnitView|null> $primary
     */
    public function __construct(
        public string $id,
        #[From('equipment')]
        public Lazy $units,
        public Lazy $primary,
    ) {}
}

final readonly class NeedsMissing extends Data
{
    public function __construct(public string $id, public string $absent) {}
}

final readonly class OptionalMissing extends Data
{
    public function __construct(public string $id, public ?string $absent, public int $count = 3) {}
}

final readonly class Plain extends Data
{
    public function __construct(public string $id, public array $tags) {}
}

final class NodeModel
{
    /** @var list<NodeModel> */
    public array $children = [];

    public ?NodeModel $parent = null;

    public function __construct(public string $id) {}
}

final readonly class NodeView extends Data
{
    /** @param list<NodeView> $children */
    public function __construct(public string $id, public array $children, public ?NodeView $parent) {}
}

final readonly class ChainView extends Data
{
    public function __construct(public string $id, public ?ChainView $next) {}
}

final class HalfBuilt
{
    public string $id = 'h';
    public string $note;
    private string $secret = 'hidden';
    public string $shout {
        get => strtoupper($this->id);
    }
}

final readonly class HalfView extends Data
{
    public function __construct(public string $id, public ?string $note = null, public ?string $secret = null, public string $shout = '') {}
}

function rack(): RackModel
{
    return new RackModel('r-1', 'R-01', [new UnitModel('u-1', 1), new UnitModel('u-2', 2)], new UnitModel('u-9', 9));
}

it('maps same-named properties, a renamed one, nested Data and a list of Data', function () {
    $view = RackView::from(rack());

    expect($view->id)->toBe('r-1')
        ->and($view->label)->toBe('R-01')
        ->and($view->units)->toHaveCount(2)
        ->and($view->units[1])->toEqual(new UnitView('u-2', 2))
        ->and($view->primary)->toEqual(new UnitView('u-9', 9))
        ->and($view->note)->toBe('none');
});

// The point of the boundary: a source field the Data class does not declare
// never reaches the payload.
it('carries only what the Data class declares', function () {
    $encoded = (new Encoder())->encode(RackView::from(rack()));

    expect(json_encode($encoded))->not->toContain('hash');
});

it('maps from an array as well as an object', function () {
    expect(UnitView::from(['id' => 'u-1', 'position' => 4, 'ignored' => true]))->toEqual(new UnitView('u-1', 4));
});

it('collects a list, and an iterable of any kind', function () {
    $gen = (static function () {
        yield new UnitModel('a', 1);
        yield new UnitModel('b', 2);
    })();

    expect(UnitView::collect($gen))->toEqual([new UnitView('a', 1), new UnitView('b', 2)]);
});

// A Lazy field defers the READ, so a relation is not loaded just because
// the object holding it was mapped.
it('reads a Lazy field only when it is resolved', function () {
    $model = rack();
    $view = LazyRackView::from($model);

    expect($model->reads)->toBe(0);

    $units = $view->units->resolve();
    expect($model->reads)->toBe(1)
        ->and($units)->toEqual([new UnitView('u-1', 1), new UnitView('u-2', 2)])
        ->and($view->primary->resolve())->toEqual(new UnitView('u-9', 9));
});

it('keeps a value that is already the Data class it maps to', function () {
    $unit = new UnitView('u-1', 1);

    expect(RackView::from(['id' => 'r', 'name' => 'n', 'equipment' => [$unit], 'primary' => $unit])->units[0])->toBe($unit);
});

it('leaves fields that are not Data as they are', function () {
    expect(Plain::from(['id' => 'p', 'tags' => ['a', 'b']])->tags)->toBe(['a', 'b']);
});

it('uses the default, else null, for a field the source lacks', function () {
    $view = OptionalMissing::from(['id' => 'x']);

    expect($view->absent)->toBeNull()->and($view->count)->toBe(3);
});

it('names a field it has nothing to map from', function () {
    expect(fn () => NeedsMissing::from(['id' => 'x']))
        ->toThrow(DataMappingException::class, "NeedsMissing::\$absent has nothing to map from: array has no 'absent'");
});

// #[MapsFrom] fixes the source: a Data class built from the wrong model is
// how a field from another table ends up in a page.
it('refuses a source other than the class it maps from', function () {
    expect(fn () => UnitView::from(rack()))
        ->toThrow(DataMappingException::class, 'UnitView maps from ' . UnitModel::class);
});

it('refuses a list field whose source is not a list', function () {
    expect(fn () => RackView::from(['id' => 'r', 'name' => 'n', 'equipment' => 'nope', 'primary' => null]))
        ->toThrow(DataMappingException::class, 'is a list, but its source is string');
});


// A parent and child relation pair is one cyclic graph; mapping it ran
// PHP out of memory -- a fatal error that takes the worker down.
it('refuses a cyclic source instead of recursing until memory runs out', function () {
    $parent = new NodeModel('p');
    $child = new NodeModel('c');
    $parent->children = [$child];
    $child->parent = $parent;

    expect(fn () => NodeView::from($parent))->toThrow(DataMappingException::class, 'the source graph is cyclic');
});

it('maps the same object twice when it is shared, not cyclic', function () {
    $shared = new UnitModel('u', 1);

    expect(RackView::from(['id' => 'r', 'name' => 'n', 'equipment' => [$shared, $shared], 'primary' => $shared])->units)
        ->toHaveCount(2);
});

it('refuses a graph deeper than the encoder would write', function () {
    $source = ['id' => 'end', 'next' => null];
    for ($i = 0; $i < 40; $i++) {
        $source = ['id' => "n{$i}", 'next' => $source];
    }

    expect(fn () => ChainView::from($source))->toThrow(DataMappingException::class, 'nests deeper than 32 levels');
});


// property_exists is true for private properties -- every Model has
// private $orm -- and for typed ones never set; both crashed the mapping.
it('treats private and unset properties as absent, and computed ones as present', function () {
    $view = HalfView::from(new HalfBuilt());

    expect($view->note)->toBeNull()
        ->and($view->secret)->toBeNull()
        ->and($view->shout)->toBe('H');
});
