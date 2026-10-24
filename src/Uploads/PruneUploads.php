<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Attributes\Schedule;

/**
 * Every fifteen minutes, removes the uploads nobody claimed: a file chosen
 * and never saved, a form abandoned, an upload refused by the action it was
 * for. See Uploads::prune().
 *
 * A task an application lists among its scheduled tasks; the Kernel puts
 * its Uploads in the container for it. An application that wants another
 * cadence writes a task of its own calling prune().
 */
#[Schedule('*/15 * * * *')]
final class PruneUploads
{
    public function __invoke(Uploads $uploads): void
    {
        $uploads->prune();
    }
}
