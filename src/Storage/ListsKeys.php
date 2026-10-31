<?php

declare(strict_types=1);

namespace Tinvar\Storage;

/**
 * A store that can say what it holds under a prefix: for pruning to find
 * a file no record names, as the upload pruning does.
 *
 * Optional: a store that cannot list, or whose credentials may not, is
 * pruned by its records alone.
 */
interface ListsKeys
{
    /**
     * Up to $limit keys under $prefix, in the store's order, each with when
     * it was last written, in seconds since the epoch. A key not shaped as
     * the framework makes them is left out.
     *
     * @return array<string, int> key => last written
     * @throws StorageException when the store could not be listed
     */
    public function keysUnder(string $prefix, int $limit): array;
}
