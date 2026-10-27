<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A CSV file a user may load, one row at a time, through a form request.
 *
 *     #[Import(name: 'stock', maxRows: 5_000),
 *         Panel('admin', permission: 'products.stock'), Authorize('restock', Product::class)]
 *     final readonly class StockImport
 *     {
 *         public function __construct(private Orm $orm) {}
 *
 *         public function __invoke(StockRow $row): void { ... }
 *     }
 *
 * A routable entry point, as tinvar-spec.md lists it: POST /_import/{name}
 * takes the file with the call -- behind the action stack and the import's
 * own tenant and panel stack, its model arguments resolved in the tenant and
 * each named by an #[Authorize] -- checks it is UTF-8 text of no more than
 * `maxBytes` and `maxRows`, headed by the columns its row declares, and
 * queues it. A class with no #[Authorize] at all fails the build.
 *
 * The row is `__invoke`'s one form-request parameter: every row of the file
 * is validated through it, as an action's input is, its model-typed fields
 * resolved in the tenant and named by an #[Authorize] like any other model
 * input. The header names the form request's fields, in any order, each
 * once, and nothing else.
 *
 * All or nothing: the rows are applied in one transaction, and a file with
 * any row that does not validate, is refused, or throws changes nothing --
 * the user is shown the failing rows' errors instead. No cell is ever
 * evaluated: a value is text the form request coerces, and one beginning
 * `=` is the text `=...`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Import
{
    /** The largest file any import takes, whatever one declares. */
    public const int MAX_BYTES_CEILING = 52_428_800;

    /** The most rows any import takes, whatever one declares: they are applied in one transaction. */
    public const int MAX_ROWS_CEILING = 100_000;

    /**
     * @param string|null $name its wire name; a dotted form of the class name unless given
     * @param int $maxBytes the largest file it takes
     * @param int $maxRows how many rows, the header apart, it takes
     */
    public function __construct(
        public ?string $name = null,
        public int $maxBytes = 5_242_880,
        public int $maxRows = 10_000,
    ) {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES_CEILING) {
            throw new \InvalidArgumentException('#[Import] maxBytes is between one byte and ' . self::MAX_BYTES_CEILING . '.');
        }
        if ($maxRows < 1 || $maxRows > self::MAX_ROWS_CEILING) {
            throw new \InvalidArgumentException('#[Import] maxRows is between one and ' . self::MAX_ROWS_CEILING . '.');
        }
    }
}
