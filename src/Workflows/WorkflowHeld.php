<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

/**
 * A unique workflow the signed-in user starts is running already, started
 * by someone else (ADR 0221): that it runs is told, and the run's id -- what
 * reads its progress and sends it signals -- is not.
 */
final class WorkflowHeld extends \RuntimeException
{
    /** @param class-string<Workflow> $workflow */
    public function __construct(public readonly string $workflow)
    {
        parent::__construct("{$workflow} is unique, and is running already, started by someone else.");
    }
}
