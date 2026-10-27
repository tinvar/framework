<?php

declare(strict_types=1);

namespace Tinvar\Data;

/**
 * A value that becomes its Data object only once it knows where it is
 * going: under which key, for which request, on which page.
 *
 * tinvar-spec.md, "Data categories": Inertia's `ProvidesInertiaProperty`
 * becomes "a `ProvidesData` interface receiving key, request and page". A
 * #[Shared] method may return one, and so may a field of the Data a page's
 * or layout's loader returns; the encoder calls toData() as it meets it,
 * read-only as the loader was, and encodes what comes back on the same
 * terms as anything else -- a model in it is refused.
 *
 * Nowhere else: a query's result and an action's have no page to be
 * placed on, and one met there is refused as any object that is not Data
 * is (ADR 0060). Codegen types the value as toData()'s return type.
 */
interface ProvidesData
{
    public function toData(DataContext $context): ?Data;
}
