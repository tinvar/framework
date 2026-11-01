<?php

declare(strict_types=1);

namespace Tinvar\Search;

use Tinvar\Jobs\Job;

/**
 * Brings a remote index up to date with rows written (ADR 0150): queued
 * by Search after the commit that wrote them, in the tenant each row is
 * in, and run there. The rows are read again on the worker, through the
 * ORM, in that tenant: one found is indexed as it is now, one missing is
 * taken out. So a job run late, twice or out of order still leaves the
 * index as the rows are -- it carries keys, never what they held.
 */
final readonly class SyncSearchDocuments implements Job
{
    /**
     * @param class-string $model
     * @param list<string> $ids
     */
    public function __construct(
        public string $model,
        public array $ids,
    ) {}

    public function handle(Search $search): void
    {
        $search->sync($this->model, $this->ids);
    }
}
