<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Console\Cron;

/**
 * When a task runs: a five-field cron expression, in UTC.
 *
 *     #[Schedule('*\/10 * * * *')]
 *     final class ShipOrders { public function __invoke(Orm $orm): void { ... } }
 *
 * "There are no routes, schedule or config files": the schedule is on the
 * task. `tinvar schedule:run`, run by cron every minute, runs what is due;
 * `tinvar schedule:work` does the same in a loop of its own.
 *
 * A task runs with no tenant, as the spec has it -- "scheduled tasks and
 * migrations run with no tenant, so tenant-scoped work in them must use
 * forEachTenant() or acrossTenants()" -- and in the panel it declares, or
 * none. Several declarations run it on each of their schedules.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Schedule
{
    public Cron $cron;

    public function __construct(public string $expression)
    {
        $this->cron = Cron::parse($expression);
    }
}
