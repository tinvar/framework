<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Orm\CursorPage;
use Tinvar\Orm\SignedCursors;

/**
 * One page of a list that `<InfiniteScroll>` loads a page at a time, read
 * by cursor: the query builder's cursorPaginate(), keyset pagination.
 *
 * A Scroll pages by number, and an order placed while the reader scrolls
 * moves every later row down a place -- the page loaded next repeats one,
 * or skips one. A cursor reads from the row it names however many were
 * added before it, so a list read this way neither repeats nor skips (ADR
 * 0057). A cursor page has no number, so the browser places each one by
 * its cursors instead: the page read at the last page's `next` goes after
 * it, the one read at the first page's `previous` before it, and a page
 * read again at its own cursor replaces itself (ADR 0120).
 *
 * The cursors travel to the browser, into the URL the next page is asked
 * for at and into the address bar as the reader scrolls. So they are
 * cursorPaginate()'s own, sealed by the Cursors the loader passed it:
 * the framework's page cursors bind each to the session, the user and their
 * auth epoch, the tenant, the panel and the build, and the builder to the
 * query -- one copied into another session, kept across a sign-out or a
 * deploy, or brought to another list opens to nothing. A page answers one
 * that does not open as it would any other request, with what the reader
 * could ask for without one.
 *
 * @template T
 */
final readonly class CursorScroll
{
    /**
     * @param list<T>     $items    this page's
     * @param string|null $cursor   the cursor this page was read at, null for the first page
     * @param string|null $previous the cursor of the page before this one, null on the first
     * @param string|null $next     the cursor of the page after this one, null on the last
     * @param string      $cursorName the query parameter the cursor is read from and written to
     * @param string|null $matchOn  the item property that identifies an item, as #[Merge]'s
     */
    public function __construct(
        public array $items,
        public ?string $cursor,
        public ?string $previous,
        public ?string $next,
        public int $perPage = 0,
        public string $cursorName = 'cursor',
        public ?string $matchOn = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $cursorName) !== 1) {
            throw new \InvalidArgumentException("'{$cursorName}' cannot be a cursor parameter.");
        }
        foreach ([$cursor, $previous, $next] as $one) {
            if ($one !== null && ($one === '' || \strlen($one) > SignedCursors::MAX_LENGTH)) {
                throw new \InvalidArgumentException('A cursor is a sealed token of up to ' . SignedCursors::MAX_LENGTH . ' bytes.');
            }
        }
    }

    /**
     * One page of the query builder's cursorPaginate(), its items already
     * Data -- CursorPage::map() them first, since a model never crosses to
     * the renderer and the encoder refuses one.
     *
     * @template U
     * @param CursorPage<U> $page
     * @return self<U>
     */
    public static function fromPage(CursorPage $page, ?string $matchOn = null, string $cursorName = 'cursor'): self
    {
        return new self($page->items, $page->cursor, $page->previous, $page->next, $page->perPage, $cursorName, $matchOn);
    }

    /**
     * The cursor a query string carries, for cursorPaginate() to open, or
     * null for the first page: a string, of a length a sealed cursor can
     * be. What it says is the cursor's to prove when it is opened.
     */
    public static function cursorFrom(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' && \strlen($value) <= SignedCursors::MAX_LENGTH ? $value : null;
    }
}
