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
 * The field is a list, or a Lazy that resolves to one -- which may be
 * #[Optional] too, so a page asks for more items by name and pays for none
 * of them on any other render. Or it is a Data object holding the list
 * under a property the mark names, Inertia's path into the prop:
 * `#[Merge(append: 'data')]` on a page of a paginator's shape merges its
 * `data` and sends the rest of it -- a count, a link -- as it is (ADR
 * 0120). The spec skips Inertia's `deepMerge`, and so does this.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Merge
{
    /** What a path into the field may be: one property of the Data object the field holds. */
    public const string PATH = '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /**
     * @param bool|string $prepend put the new items before those shown rather than after; a
     *        property name says so of the list under that property of the field
     * @param string|null $matchOn the item property that identifies an item: an item arriving
     *        again replaces the one shown, where it is, instead of being added twice. It keys the
     *        rendered item, so an item rendered with a React key of its own needs none.
     * @param string|null $append the property of the field the list to put after those shown
     *        is under, for a field that is a Data object holding one
     */
    public function __construct(
        public bool|string $prepend = false,
        public ?string $matchOn = null,
        public ?string $append = null,
    ) {
        if ($prepend !== false && $append !== null) {
            throw new \InvalidArgumentException('#[Merge] puts its items after those shown or before them, not both.');
        }
        $path = $this->path();
        if ($path !== null && preg_match(self::PATH, $path) !== 1) {
            throw new \InvalidArgumentException("#[Merge] merges into '{$path}', which is not a property name.");
        }
    }

    /** The property of the field the list is under, or null for a field that is the list. */
    public function path(): ?string
    {
        return \is_string($this->prepend) ? $this->prepend : $this->append;
    }

    /** Whether the new items go before those shown. */
    public function prepends(): bool
    {
        return $this->prepend !== false;
    }
}
