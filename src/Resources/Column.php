<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * One column of a resource's list, and one line of its record's page.
 *
 *     Column::make('name')->sortable()->searchable(),
 *     Column::make('priceCents', 'Price')->money()->sortable(),
 *     Column::make('customer')->labelledBy('email'),
 *
 * Named by the model's property, and drawn as that property is typed --
 * text, a number, an enum's label, a moment, yes or no, or the label of the
 * record it belongs to -- unless it says otherwise. A property the model
 * does not have is refused when the resource is registered, saying which.
 */
final readonly class Column
{
    private function __construct(
        public string $key,
        public ?string $label = null,
        public ?ColumnKind $kind = null,
        public bool $sortable = false,
        public bool $searchable = false,
        public ?string $labelledBy = null,
    ) {}

    /**
     * @param string $key the model's property
     * @param string|null $label what the column is headed, `Price cents` for priceCents by default
     */
    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label);
    }

    /** The list may be sorted by it: a link on its heading, read back from `?sort=`. */
    public function sortable(): self
    {
        return clone($this, ['sortable' => true]);
    }

    /**
     * The list's search box looks in it: a text column, matched anywhere in
     * it -- unless the resource answers searches itself (Resource::search()).
     */
    public function searchable(): self
    {
        return clone($this, ['searchable' => true]);
    }

    /** An int of cents, drawn as money. */
    public function money(): self
    {
        return clone($this, ['kind' => ColumnKind::Money]);
    }

    /** For a record it belongs to: the property of that record it is labelled by, `name` by default. */
    public function labelledBy(string $property): self
    {
        return clone($this, ['labelledBy' => $property]);
    }
}
