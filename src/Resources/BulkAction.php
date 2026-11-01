<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * Something a resource's list does to the rows ticked in it, at once.
 *
 *     BulkAction::delete(),
 *     BulkAction::make('restock', 'Add ten to stock', ability: 'update', method: 'restockTen'),
 *
 * Each row is read again, scoped to the tenant, and its policy asked the
 * action's ability -- `delete` for a row, as the delete button asks -- and
 * if any one is refused, none is changed: the action runs in one
 * transaction, over every row or over none. A method names the resource's
 * own, taking one record: `public function restockTen(Product $product): void`.
 */
final readonly class BulkAction
{
    /** The most rows one bulk action takes. */
    public const int MAX_ROWS = 100;

    private function __construct(
        public string $key,
        public string $label,
        public string $ability,
        public ?string $method,
        public bool $destructive,
    ) {}

    /** Deletes each row ticked: the delete button, for many. */
    public static function delete(string $label = 'Delete'): self
    {
        return new self('delete', $label, 'delete', null, true);
    }

    /**
     * @param string $key how the list names it when it posts, letters and digits
     * @param string $ability what each row's policy is asked
     * @param string $method the resource's method that does it, to one record
     * @param bool $destructive whether the list asks before it runs
     */
    public static function make(string $key, string $label, string $ability, string $method, bool $destructive = false): self
    {
        return new self($key, $label, $ability, $method, $destructive);
    }
}
