<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A query whose rows a user may take away as a file: CSV, or JSON lines.
 *
 *     #[Export(OrderExportRow::class, name: 'orders'),
 *         Panel('admin', permission: 'orders.export'), Authorize('export', Order::class)]
 *     final readonly class OrdersExport
 *     {
 *         public function __construct(private Orm $orm) {}
 *
 *         public function __invoke(): Query
 *         {
 *             return Order::query($this->orm)->orderBy('placedAt', Direction::Desc);
 *         }
 *     }
 *
 * A routable entry point, as tinvar-spec.md lists it beside pages, actions
 * and queries: POST /_export/{name} starts one, behind the action stack and
 * the export's own tenant and panel stack, its model arguments resolved in
 * the tenant and each named by an #[Authorize] -- and a class with no
 * #[Authorize] at all fails the build, as a query with none does. It runs
 * as a queued job, as the user who asked, in their tenant and panel; the
 * file it writes is fetched through a signed link bound to that user and
 * their auth epoch (Transfers\DownloadToken).
 *
 * `row` is the Data class each row is written as. Its constructor's
 * parameters are the file's columns, in order, by name, and each is a
 * scalar or a backed enum: the column set comes from a class written for
 * it, never from the model, so a column nobody chose to export -- a
 * password hash, an internal flag -- cannot reach the file. A model is
 * mapped to it with Data::from(), or by the export's own `row()` method
 * when it declares one.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Export
{
    public const string CSV = 'csv';
    public const string JSONL = 'jsonl';

    /** The most rows any export writes, whatever one declares. */
    public const int MAX_ROWS_CEILING = 5_000_000;

    /** @var non-empty-list<string> */
    public array $formats;

    /**
     * @param class-string<\Tinvar\Data\Data> $row the Data class each row is written as
     * @param string|null $name its wire name; a dotted form of the class name unless given
     * @param array<mixed> $formats which of CSV and JSONL it may be written as, the first the default
     * @param int $maxRows how many rows one export writes at most; one that would write more fails
     */
    public function __construct(
        public string $row,
        public ?string $name = null,
        array $formats = [self::CSV, self::JSONL],
        public int $maxRows = 1_000_000,
    ) {
        $list = [];
        foreach ($formats as $format) {
            if ($format !== self::CSV && $format !== self::JSONL) {
                throw new \InvalidArgumentException("#[Export] formats are 'csv' and 'jsonl'.");
            }
            if (!\in_array($format, $list, true)) {
                $list[] = $format;
            }
        }
        if ($list === []) {
            throw new \InvalidArgumentException('#[Export] needs a format to write.');
        }
        if ($maxRows < 1 || $maxRows > self::MAX_ROWS_CEILING) {
            throw new \InvalidArgumentException('#[Export] maxRows is between one and ' . self::MAX_ROWS_CEILING . '.');
        }

        $this->formats = $list;
    }
}
