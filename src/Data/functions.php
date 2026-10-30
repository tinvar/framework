<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Orm\CursorPage;
use Tinvar\Orm\Page;

/*
 * Inertia's `scroll(paginator)` as the spec renames it: a page of an ORM
 * paginator as a Scroll field, `<InfiniteScroll>`'s (tinvar-spec.md, "Data
 * categories"). Its items are Data already -- map() them first.
 *
 *     orders: $query->paginate($page, 10)->map(OrderSummaryData::from(...)) |> scroll(...),
 *
 * A page-numbered Page makes a Scroll; a cursorPaginate() CursorPage makes
 * a CursorScroll, which <InfiniteScroll> places by its cursors (ADR 0120).
 *
 * In the PSR-4 directory, so the layer rule reads it as the framework's,
 * and loaded by Composer by its path (`files`). Declared only once, should
 * anything ask the class loader for a class this path would name.
 */

if (!\function_exists(__NAMESPACE__ . '\\scroll')) {
    /**
     * @template T
     * @template P of Page<T>|CursorPage<T>
     * @param P $page
     * @param string|null $pageName the query parameter: `page` for a Page, `cursor` for a CursorPage
     * @return (P is Page<T> ? Scroll<T> : CursorScroll<T>)
     */
    function scroll(Page|CursorPage $page, ?string $matchOn = null, ?string $pageName = null): Scroll|CursorScroll
    {
        return $page instanceof Page
            ? Scroll::fromPage($page, $matchOn, $pageName ?? 'page')
            : CursorScroll::fromPage($page, $matchOn, $pageName ?? 'cursor');
    }
}
