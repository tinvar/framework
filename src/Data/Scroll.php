<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Orm\Page;

/**
 * One page of a list that `<InfiniteScroll>` loads a page at a time.
 *
 * tinvar-spec.md, "Data categories": `Inertia::scroll(paginator)` becomes
 * `scroll()`. A Scroll field is merged as a #[Merge] field is -- the page
 * renders only this page's items and the browser keeps the rest -- but by
 * page number rather than by arrival: a page loaded again replaces itself
 * where it sits, the previous page goes before the first one shown and the
 * next after the last, whatever order they come back in. `X-Reset` naming
 * the field replaces them all. ADR 0020.
 *
 * Built from the page the loader read: an ORM paginator's (fromPage(), or
 * scroll() at the end of a pipe), or one the loader counted itself
 * (paginate()). A page number carries nothing the server trusts -- it is a
 * position in rows the reader may see -- so it travels in the URL as it is
 * (ADR 0057).
 *
 * @template T
 */
final readonly class Scroll
{
    /** What a page number in a query string may look like: positive, and short enough to stay an int. */
    private const string PAGE = '/^[1-9][0-9]{0,8}\z/';

    /**
     * @param list<T>     $items    this page's
     * @param int|null    $previous the page before this one, null on the first
     * @param int|null    $next     the page after this one, null on the last
     * @param int|null    $total    items across every page, where the loader counted them
     * @param string      $pageName the query parameter the page number is read from and written to
     * @param string|null $matchOn  the item property that identifies an item, as #[Merge]'s
     */
    public function __construct(
        public array $items,
        public int $page,
        public ?int $previous,
        public ?int $next,
        public ?int $total = null,
        public int $perPage = 0,
        public string $pageName = 'page',
        public ?string $matchOn = null,
    ) {
        if ($page < 1 || ($previous !== null && $previous < 1) || ($next !== null && $next < 1)) {
            throw new \InvalidArgumentException('A Scroll counts its pages from 1.');
        }
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $pageName) !== 1) {
            throw new \InvalidArgumentException("'{$pageName}' cannot be a page parameter.");
        }
    }

    /**
     * A page of a list whose length is known: the pages either side of it
     * are those that exist. Past the end, the page before is the last one
     * -- a reader landing on `?page=99` loads back from what there is, not
     * through ninety-seven empty pages.
     *
     * @template U
     * @param list<U> $items
     * @return self<U>
     */
    public static function paginate(
        array $items,
        int $page,
        int $perPage,
        int $total,
        string $pageName = 'page',
        ?string $matchOn = null,
    ): self {
        $last = $perPage > 0 ? max(1, intdiv($total + $perPage - 1, $perPage)) : 1;

        return new self(
            items: $items,
            page: $page,
            previous: $page > 1 ? min($page - 1, $last) : null,
            next: $page * $perPage < $total ? $page + 1 : null,
            total: $total,
            perPage: $perPage,
            pageName: $pageName,
            matchOn: $matchOn,
        );
    }

    /**
     * One page of an ORM paginator (Query::paginate()), its items already
     * Data -- Page::map() them first, since a model never crosses to the
     * renderer and the encoder refuses one.
     *
     * @template U
     * @param Page<U> $page
     * @return self<U>
     */
    public static function fromPage(Page $page, ?string $matchOn = null, string $pageName = 'page'): self
    {
        return self::paginate($page->items, $page->page, $page->perPage, $page->total, $pageName, $matchOn);
    }

    /**
     * The page number a query string asks for: a positive integer, or the
     * first page. Bounded, so `?page=99999999999999999999` is a page past
     * the end rather than an integer overflow in an OFFSET.
     */
    public static function pageFrom(mixed $value): int
    {
        return \is_string($value) && preg_match(self::PAGE, $value) === 1 ? (int) $value : 1;
    }
}
