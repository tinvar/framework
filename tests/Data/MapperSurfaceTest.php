<?php

declare(strict_types=1);

namespace Tinvar\Tests\Data\Surface;

use Tinvar\Data\Data;
use Tinvar\Tests\Data\Elsewhere\{ItemData, ItemData as GroupedItem};

// This import and the next take ItemData's name in the function and
// constant tables. Read as class imports, they would replace it.
use function Tinvar\Tests\Data\Surface\Helpers\itemdata;

use const Tinvar\Tests\Data\Surface\Helpers\ITEMDATA;

require_once __DIR__ . '/Fixtures/elsewhere.php';

final readonly class Grouped extends Data
{
    /**
     * @param list<ItemData>    $items   imported in a group
     * @param list<GroupedItem> $aliased aliased in a group
     */
    public function __construct(public array $items, public array $aliased) {}
}

final readonly class InlineVar extends Data
{
    public function __construct(
        /** @var list<ItemData> */
        public array $items,
    ) {}
}

final readonly class Local extends Data
{
    public function __construct(public string $id) {}
}

it('resolves docblock names through grouped use imports, passing over function and constant imports', function () {
    $data = Grouped::from(['items' => [['id' => 'a']], 'aliased' => [['id' => 'b']]]);

    expect($data->items[0])->toBeInstanceOf(ItemData::class)
        ->and($data->items[0]->id)->toBe('a')
        ->and($data->aliased[0])->toBeInstanceOf(ItemData::class)
        ->and($data->aliased[0]->id)->toBe('b');
});

it('reads the element type from an @var on a promoted parameter', function () {
    $data = InlineVar::from(['items' => [['id' => 'a'], ['id' => 'b']]]);

    expect($data->items)->toHaveCount(2)
        ->and($data->items[1])->toBeInstanceOf(ItemData::class)
        ->and($data->items[1]->id)->toBe('b');
});

// A class with no file -- eval'd, or generated at runtime -- has no imports
// to read; a short name still resolves in its own namespace.
it('resolves a short name in the namespace of a class that has no file', function () {
    if (!class_exists(Evaluated::class, false)) {
        eval(<<<'PHP'
            namespace Tinvar\Tests\Data\Surface;

            final readonly class Evaluated extends \Tinvar\Data\Data
            {
                /** @param list<Local> $items */
                public function __construct(public array $items) {}
            }
            PHP);
    }

    $data = Evaluated::from(['items' => [['id' => 'x']]]);

    expect($data->items[0])->toBeInstanceOf(Local::class)->and($data->items[0]->id)->toBe('x');
});

// PHP keeps imports per namespace block. Read per file, and only outside
// braces, a braced block's imports were missed and two blocks' clashed.
it('resolves imports inside braced namespace blocks, each against its own', function () {
    require_once __DIR__ . '/Fixtures/braced.php';

    $first = \Tinvar\Tests\Data\Braced\First\FirstHolder::from(['items' => [['id' => 'a']]]);
    $second = \Tinvar\Tests\Data\Braced\Second\SecondHolder::from(['items' => [['name' => 'b']]]);

    expect($first->items[0])->toBeInstanceOf(\Tinvar\Tests\Data\Braced\Parts\Part::class)
        ->and($second->items[0])->toBeInstanceOf(\Tinvar\Tests\Data\Braced\Parts\Other::class);
});

// A closure's `use (...)` is not an import, and reading it as one -- then
// skipping its body's braces uncounted -- lost every import after it.
it('does not mistake a closure use at file scope for an import', function () {
    require_once __DIR__ . '/Fixtures/braced.php';
    require_once __DIR__ . '/Fixtures/closure-at-file-scope.php';

    $data = \Tinvar\Tests\Data\FileScope\AfterClosure::from(['parts' => [['id' => 'p']]]);

    expect($data->parts[0])->toBeInstanceOf(\Tinvar\Tests\Data\Braced\Parts\Part::class);
});
