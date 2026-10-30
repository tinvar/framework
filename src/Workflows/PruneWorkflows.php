<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Attributes\Schedule;

/**
 * Every night, forgets the workflows that ended a month ago, and the
 * signals sent to them (ADR 0119): past that, their progress answers no
 * one's question. A task an application lists among its scheduled tasks,
 * beside the WorkflowStore it registers in its container.
 */
#[Schedule('29 4 * * *')]
final class PruneWorkflows
{
    /** Thirty days. */
    public const int KEEP = 2_592_000;

    public function __invoke(WorkflowStore $store): void
    {
        $store->prune(time() - self::KEEP);
    }
}
