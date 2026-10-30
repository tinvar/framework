<?php

declare(strict_types=1);

namespace Tinvar\Workflows;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\Refusal;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Queue\Wakes;

/**
 * Starts workflows, runs each one's next step on the worker, and -- given a
 * store -- keeps their progress and takes the signals sent to them.
 *
 * A workflow is a job (RunWorkflow) on the application's queue, so it is
 * durable where the queue is -- the jobs table, beside the rows the steps
 * write -- and is sealed and checked as every job is: the tenant it was
 * started in, its panel (its own #[Panel], else the starter's), and the
 * user who started it on their auth epoch. Started inside a transaction it
 * waits for the commit, and is never started if that rolls back.
 *
 * **Progress and signals** (ADR 0119) are kept in a WorkflowStore: a record
 * of each workflow -- its status, the step it last took, when it next runs
 * -- written at its start, after each run and at its end, and the signals
 * sent to it, each kept once by name. Both are bound to where the workflow
 * was started: progress() reads a record only in its tenant and panel and,
 * unless asked otherwise, for the user who started it; signal() sends one
 * only in its tenant and panel. A signal is the sender's data, plain and
 * small, and the step that waits for it reads it as input. Who may send
 * one is the code that calls signal(): an action behind its #[Authorize],
 * a service call, a webhook the application verified.
 *
 * The application registers one in its container, so the job can ask for
 * it by type:
 *
 *     $container->instance(Workflows::class, new Workflows($dispatcher, $container->resolver(),
 *         new DatabaseWorkflowStore($connection), $orm, $auth, $panels, $queue));
 */
final readonly class Workflows
{
    /**
     * @param \Closure(class-string): object $resolver builds what a workflow's run() asks for
     * @param WorkflowStore|null $store where progress and signals are kept; without it, a
     *        workflow keeps no record, and a wait on a signal, signal() and progress() are refused
     * @param Wakes|null $queue the queue, where it can make a waiting job available at once:
     *        what a signal does to the workflow waiting on it
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private \Closure $resolver,
        private ?WorkflowStore $store = null,
        private ?Orm $orm = null,
        private ?AuthContext $auth = null,
        private ?PanelContext $panels = null,
        private ?Wakes $queue = null,
        private ?\Closure $clock = null,
    ) {
        if ($store !== null && ($orm === null || $auth === null)) {
            throw new \InvalidArgumentException('A workflow store is read and written where workflows were started: give the Orm and the AuthContext with it.');
        }
    }

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

        $id = $this->dispatcher->dispatch(new RunWorkflow($workflow), $delay);
        if ($this->store !== null && $this->orm !== null && $this->auth !== null) {
            // Written beside the job -- in the transaction it waits for,
            // where the store shares the application's connection. A unique
            // workflow already running answers with its id, whose record is
            // kept already.
            $now = $this->now();
            $this->store->start(new WorkflowRecord(
                id: $id,
                workflow: $workflow::class,
                tenant: $this->orm->tenant(),
                panel: $this->panels?->currentOrNull() ?? '',
                user: $this->auth->id() ?? '',
                status: WorkflowRecord::QUEUED,
                step: null,
                steps: 0,
                wakeAt: $delay > 0 ? $now + $delay : null,
                startedAt: $now,
                updatedAt: $now,
            ));
        }

        return $id;
    }

    /**
     * Sends the signal $name to workflow $id, with $data for the step that
     * waits for it (Steps::waitFor()), and wakes it if it is waiting.
     *
     * Only in the tenant and panel the workflow was started in, as a write
     * is -- never in a loader or a query body, nor across tenants -- and
     * once a name: a second signal of the same name is not kept. False for a
     * workflow that is not there, not this tenant's and panel's, or has
     * ended, as for one that was signalled so already; an id says nothing
     * about any workflow elsewhere.
     *
     * @param array<array-key, mixed> $data plain data, at most Steps::MAX_SIGNAL_BYTES of JSON
     */
    public function signal(string $id, string $name, array $data = []): bool
    {
        [$store, $orm] = $this->kept('A signal');
        if ($orm->isReadOnly()) {
            throw ReadOnlyViolation::write('A workflow signal', 'a loader or query body');
        }
        if ($orm->isUnscoped()) {
            throw new \LogicException('A workflow signal cannot be sent inside acrossTenants(): it is sent in the tenant the workflow runs in.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a signal's name: letters, digits, '.', '_', ':' and '-', up to 64.");
        }
        self::assertPlain($data, 0);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (\strlen($json) > Steps::MAX_SIGNAL_BYTES) {
            throw new \LengthException('A signal\'s data is at most ' . Steps::MAX_SIGNAL_BYTES . ' bytes of JSON: send a reference, not the work.');
        }

        $record = $store->find($id);
        if ($record === null || $record->ended()
            || !hash_equals($record->tenant, $orm->tenant())
            || !hash_equals($record->panel, $this->panels?->currentOrNull() ?? '')) {
            return false;
        }

        $now = $this->now();
        if (!$store->signal($id, $name, $json, $now)) {
            return false;
        }
        $this->queue?->wake($id, $now);

        return true;
    }

    /**
     * Workflow $id's progress, read only where it was started: in the same
     * tenant and panel, and by the user who started it -- or, with
     * $anyStarter, by anyone in that tenant and panel, for an application
     * whose own authorization has decided they may. Null for any other, as
     * for one that does not exist.
     */
    public function progress(string $id, bool $anyStarter = false): ?WorkflowRecord
    {
        [$store, $orm, $auth] = $this->kept('A workflow\'s progress');
        $record = $store->find($id);
        if ($record === null
            || !hash_equals($record->tenant, $orm->tenant())
            || !hash_equals($record->panel, $this->panels?->currentOrNull() ?? '')
            || (!$anyStarter && !hash_equals($record->user, $auth->id() ?? ''))) {
            return null;
        }

        return $record;
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
        $id = $job->id();
        $store = $this->store;
        $signals = $store === null ? null : static function (string $name) use ($store, $id): ?array {
            $payload = $store->received($id, $name);
            if ($payload === null) {
                return null;
            }
            $data = json_decode($payload, true, 16);

            return \is_array($data) ? $data : [];
        };
        $now = $this->now();
        $steps = new Steps($job, $signals, $now);

        try {
            $this->call($workflow, $steps);
        } catch (StepTaken $taken) {
            // Another run's Steps cannot reach here: each run builds its own.
            if ($taken->steps !== $steps) {
                throw $taken;
            }
        }

        if (!$steps->ended()) {
            $store?->update($id, WorkflowRecord::FINISHED, null, $steps->count(), null, $now);

            return;
        }

        $pause = $steps->pause();
        $pause['counted'] ? $job->release($pause['delay'], $pause['checkpoint']) : $job->wait($pause['delay'], $pause['checkpoint']);
        $store?->update($id, $pause['status'], $pause['step'], $pause['steps'], $pause['delay'] > 0 ? $now + $pause['delay'] : null, $now);
    }

    /**
     * Its record ended: failed, after its last try, or refused, its context
     * no longer holding -- an epoch moved, a membership gone.
     *
     * @internal RunWorkflow calls it from its job's failed() and refused() hooks
     */
    public function ended(JobControl $job, \Throwable|Refusal $why): void
    {
        if ($this->store === null) {
            return;
        }
        $steps = $this->store->find($job->id())->steps ?? 0;
        $this->store->update($job->id(), $why instanceof Refusal ? WorkflowRecord::REFUSED : WorkflowRecord::FAILED, null, $steps, null, $this->now());
    }

    /**
     * The store, and what reading and writing it where workflows were
     * started needs.
     *
     * @return array{WorkflowStore, Orm, AuthContext}
     */
    private function kept(string $what): array
    {
        if ($this->store === null || $this->orm === null || $this->auth === null) {
            throw new \LogicException("{$what} needs a workflow store (WorkflowStore), and Workflows was given none.");
        }

        return [$this->store, $this->orm, $this->auth];
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

    private static function assertPlain(mixed $value, int $depth): void
    {
        if ($depth > 8) {
            throw new \InvalidArgumentException('A signal\'s data nests at most 8 deep.');
        }
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($item, $depth + 1);
            }

            return;
        }
        if (($value !== null && !\is_scalar($value)) || (\is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('A signal\'s data is plain data -- strings, numbers, booleans, null and arrays of them -- and this holds ' . get_debug_type($value) . '.');
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
