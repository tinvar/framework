<?php

declare(strict_types=1);

namespace Tinvar\Search;

/**
 * A row's key and the tenant it is in, read together in one statement
 * (Search::tenantsOf()): read apart, a row deleted between the two reads
 * left the two lists apart, and no row of the sync was indexed.
 *
 * @internal for Search
 */
final readonly class SearchRowTenant
{
    public function __construct(
        public string $id,
        public string $tenant,
    ) {}
}
