<?php

declare(strict_types=1);

/**
 * A `@var` tag with a description after the type.
 *
 * The description was read as part of the type, so `list<ItemData> items in
 * display order` named no Data class: the field was left unmapped, holding
 * its source as it was, and failed later at the encoder or the constructor.
 */

namespace Tinvar\Tests\Data\VarDescription;

use Tinvar\Data\Data;
use Tinvar\Tests\Data\Elsewhere\ItemData;

require_once __DIR__ . '/Fixtures/elsewhere.php';

final readonly class Described extends Data
{
    public function __construct(
        /** @var list<ItemData> items in display order */
        public array $items,
        /** @var array<string, ItemData> by key, for lookups */
        public array $byKey,
    ) {}
}

it('reads the type of a @var tag that has a description', function () {
    $view = Described::from([
        'items' => [['id' => 'a']],
        'byKey' => ['k' => ['id' => 'b']],
    ]);

    expect($view->items)->toEqual([new ItemData('a')])
        ->and($view->byKey)->toEqual(['k' => new ItemData('b')]);
});
