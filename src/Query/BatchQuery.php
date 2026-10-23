<?php

declare(strict_types=1);

namespace Tinvar\Query;

/**
 * "A query class can implement it to turn twenty rackStats(id) calls into one
 * whereIn." (tinvar-spec.md, "Batching and dedupe")
 *
 * The runner hands every call of this query made in one tick to loadMany(), so
 * the class sees the whole batch and issues one statement for it.
 */
interface BatchQuery
{
    /**
     * @param list<array<string, mixed>> $calls argument maps, already deduped
     * @return list<mixed> one result per call, in the same order
     */
    public function loadMany(array $calls): array;
}
