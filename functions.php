<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Orm\Page;

/**
 * Inertia's `scroll(paginator)` as the spec renames it: a page of an ORM
 * paginator as a Scroll field, `<InfiniteScroll>`'s (tinvar-spec.md, "Data
 * categories"). Its items are Data already -- Page::map() them first.
 *
 *     orders: $query->paginate($page, 10)->map(OrderSummaryData::from(...)) |> scroll(...),
 *
 * Beside src/ rather than in it: Composer loads a file of functions by its
 * path, and a PSR-4 directory holds classes.
 *
 * @template T
 * @param Page<T> $page
 * @return Scroll<T>
 */
function scroll(Page $page, ?string $matchOn = null, string $pageName = 'page'): Scroll
{
    return Scroll::fromPage($page, $matchOn, $pageName);
}
