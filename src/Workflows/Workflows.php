<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\JobControl;

/**
 * Starts workflows, and runs each one's next step on the worker.
 *
 * A workflow is a job (RunWorkflow) on the application's queue, so it is
 * durable where the queue is -- the jobs table, beside the rows the steps
 * write -- and is sealed and checked as every job is: the tenant it was
 * started in, its panel (its own #[Panel], else the starter's), and the
 * user who started it on their auth epoch. Started inside a transaction it
 * waits for the commit, and is never started if that rolls back.
 *
 * The application registers one in its container, so the job can ask for
 * it by type:
 *
 *     $container->instance(Workflows::class, new Workflows($dispatcher, $container->resolver()));
 */
final readonly class Workflows
{
    /**
     * @param \Closure(class-string): object $resolver builds what a workflow's run() asks for
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private \Closure $resolver,
    ) {}

    /**
     * Starts $workflow, to take its first step no sooner than $delay
     * seconds from now.
     *
     * Refused where a job's dispatch is: in a loader or a query body, and
     * inside acrossTenants().
     *
     * @return string the workflow's id, which is its job's
     */
    public function start(Workflow $workflow, int $delay = 0): string
    {
        if (!method_exists($workflow, 'run')) {
            throw new \LogicException($workflow::class . ' has no run() to take its steps in.');
        }

        return $this->dispatcher->dispatch(new RunWorkflow($workflow), $delay);
    }

    /**
     * One run of $workflow: its finished steps replayed, the next taken,
     * and the job paused with that step recorded -- or, with none left,
     * finished.
     *
     * @internal RunWorkflow calls it, in the context the runner checked
     */
    public function advance(Workflow $workflow, JobControl $job): void
    {
        $steps = new Steps($job);

        try {
            $this->call($workflow, $steps);
        } catch (StepTaken $taken) {
            // Another run's Steps cannot reach here: each run builds its own.
            if ($taken->steps !== $steps) {
                throw $taken;
            }
        }

        if ($steps->ended()) {
            ['delay' => $delay, 'checkpoint' => $checkpoint] = $steps->pause();
            $job->release($delay, $checkpoint);
        }
    }

    private function call(Workflow $workflow, Steps $steps): void
    {
        $arguments = [];
        foreach (new \ReflectionMethod($workflow, 'run')->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new \LogicException(
                    $workflow::class . "::run() takes \${$parameter->getName()}, which is not a service: a workflow's data belongs in its constructor.",
                );
            }
            $class = $type->getName();
            if ($class === Steps::class) {
                $arguments[] = $steps;
                continue;
            }
            // The run's control is the job's, never the workflow's: pausing
            // it by hand would skip the step's record.
            if ($class === JobControl::class) {
                throw new \LogicException($workflow::class . '::run() takes a JobControl; a workflow pauses by its Steps.');
            }
            /** @var class-string $class */
            $arguments[] = ($this->resolver)($class);
        }

        $run = [$workflow, 'run'];
        if (!\is_callable($run)) {
            throw new \LogicException($workflow::class . '::run() is not public.');
        }
        $run(...$arguments);
    }
}
