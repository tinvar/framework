<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * A choice above a resource's list that narrows it to the rows whose
 * property has one value, read back from `?status=placed`.
 *
 *     Filter::make('status'),                    an enum's cases
 *     Filter::make('category')->distinct(),      the values the table holds
 *     Filter::make('size')->options(['s' => 'Small', 'l' => 'Large']),
 *
 * A value that is not one of its options is ignored, as no filter: the
 * list is never narrowed by anything the server did not offer.
 */
final readonly class Filter
{
    /** The most values distinct() offers. */
    public const int MAX_DISTINCT = 100;

    /** @param array<string, string>|null $options value => label */
    private function __construct(
        public string $key,
        public ?string $label = null,
        public ?array $options = null,
        public bool $distinct = false,
    ) {}

    public static function make(string $key, ?string $label = null): self
    {
        return new self($key, $label);
    }

    /** @param array<string, string> $options value => label */
    public function options(array $options): self
    {
        return clone($this, ['options' => $options]);
    }

    /** The values the table holds in the column, read when the list is: up to MAX_DISTINCT, in order. */
    public function distinct(): self
    {
        return clone($this, ['distinct' => true]);
    }
}
