<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

/**
 * Thrown past the rest of a workflow's run() once it has taken its one new
 * step, so the step is recorded before the next one runs (Steps).
 *
 * An Error, not an Exception, so a `catch (\Exception)` around a step lets
 * it by. Code that catches it anyway changes nothing: Steps refuses a
 * second new step in the run, and the run ends with the one it took.
 *
 * @internal
 */
final class StepTaken extends \Error
{
    public function __construct(public readonly Steps $steps)
    {
        parent::__construct('A workflow took a step, and its run ends here.');
    }
}
