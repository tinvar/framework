<?php

declare(strict_types=1);

/**
 * A Data field typed with Lazy's name from before the split (ADR 0055),
 * `Tinvar\Data\Lazy`, an alias of `Tinvar\Support\Lazy` for one release:
 * the mapper defers it as it defers the new name, rather than taking it for
 * a plain value.
 */

namespace Tinvar\Tests\Data\RenamedLazy;

use Tinvar\Attributes\MapsFrom;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;

final class Shelf
{
    public int $reads = 0;

    /** @var list<Book> */
    public array $books {
        get {
            $this->reads++;

            return [new Book('b-1'), new Book('b-2')];
        }
    }
}

final class Book
{
    public function __construct(public string $id) {}
}

#[MapsFrom(Book::class)]
final readonly class BookView extends Data
{
    public function __construct(public string $id) {}
}

#[MapsFrom(Shelf::class)]
final readonly class ShelfView extends Data
{
    /** @param Lazy<list<BookView>> $books */
    public function __construct(public \Tinvar\Data\Lazy $books) {}
}

it('maps a field typed with the old Lazy name lazily, and encodes what it resolves to', function () {
    set_error_handler(static fn (int $level): bool => $level === \E_USER_DEPRECATED);

    try {
        $shelf = new Shelf();
        $view = ShelfView::from($shelf);
        $readsBefore = $shelf->reads;
        $encoded = (new Encoder())->encode($view);
    } finally {
        restore_error_handler();
    }

    expect($readsBefore)->toBe(0)
        ->and($shelf->reads)->toBe(1)
        ->and(json_encode($encoded))->toContain('b-2');
});
