<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

/**
 * A unique job (#[Queue(unique: seconds)]) that is unique per something of
 * its own -- an order, an account -- rather than per its arguments, which a
 * unique job is unique by unless it says otherwise (ADR 0118):
 *
 *     #[Queue('sync', unique: 600)]
 *     final readonly class SyncInventory implements Job, UniqueJob
 *     {
 *         public function __construct(public Warehouse $warehouse) {}
 *
 *         public function uniqueId(): string
 *         {
 *             return $this->warehouse->id;
 *         }
 *     }
 *
 * The id is one part of the lock's key, beside the class, the tenant, the
 * panel and -- for a job that runs as its dispatcher -- the user, so it
 * never needs to say any of those, and no tenant's job can hold another
 * tenant's lock. The key is a digest: the id is not stored. A uniqueId()
 * of '' makes the job one per class, whatever its arguments.
 */
interface UniqueJob
{
    public function uniqueId(): string;
}
