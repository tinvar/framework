<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * One row as an index holds it (ADR 0152): its key, and the text of each
 * of its model's four weight classes, A to D (SearchableModel).
 *
 * Nothing else: no tenant -- the backend is told it beside the document,
 * by the code that read the row in that tenant's scope -- and none of the
 * row's other columns. A search answers keys, which are read again through
 * the ORM, so what an index holds is only what it was made to match by.
 */
final readonly class SearchDocument
{
    /** The most bytes of one field's text indexed: the rest is not searched. */
    public const int MAX_TEXT = 65_536;

    /** @param list<string> $classes the text of each weight class, A to D */
    public function __construct(
        public string $id,
        public array $classes,
    ) {}
}
