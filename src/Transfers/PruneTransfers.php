<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Attributes\Schedule;

/**
 * Every hour, removes each export's file once it has expired, and every
 * record a month old. See Transfers::prune().
 *
 * A task an application lists among its scheduled tasks; the Kernel puts
 * its Transfers in the container for it.
 */
#[Schedule('17 * * * *')]
final class PruneTransfers
{
    public function __invoke(Transfers $transfers): void
    {
        $transfers->prune();
    }
}
