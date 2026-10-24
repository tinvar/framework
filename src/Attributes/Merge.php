<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a list field whose items a partial reload adds to what the browser
 * shows, rather than replacing it.
 *
 * tinvar-spec.md, "Data categories": `#[Merge(append: 'data', matchOn:
 * 'id')]`, Inertia's merge props. Under RSC the browser holds no list to
 * merge into, only what was rendered from one (ADR 0015), so the merge
 * happens to rendered items: the page renders the field through `<Merged>`,
 * which renders only the items this response carries, and a client
 * component holding what earlier responses rendered puts them before or
 * after the rest. `X-Reset` naming the field replaces instead. ADR 0020.
 *
 * The field is the list itself, so there is no `append: 'data'` path into
 * it; a paginated list is a Scroll, which merges by page.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Merge
{
    /**
     * @param bool        $prepend put the new items before those shown rather than after
     * @param string|null $matchOn the item property that identifies an item: an item arriving
     *        again replaces the one shown, where it is, instead of being added twice. It keys the
     *        rendered item, so an item rendered with a React key of its own needs none.
     */
    public function __construct(
        public bool $prepend = false,
        public ?string $matchOn = null,
    ) {}
}
