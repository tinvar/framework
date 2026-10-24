<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Attributes\Schedule;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\RequestScope;
use Tinvar\Jobs\DeclaredPanel;
use Tinvar\Orm\Orm;

/**
 * Runs the tasks whose #[Schedule] is due: `tinvar schedule:run`, every
 * minute from cron, or `tinvar schedule:work`, which keeps the minute
 * itself.
 *
 * A task is a class with an `__invoke()` whose parameters are services,
 * built by the container. Each one:
 *
 *   - runs once per minute it is due, across every scheduler (ScheduleStore);
 *   - runs with no tenant -- "scheduled tasks and migrations run with no
 *     tenant, so tenant-scoped work in them must use forEachTenant() or
 *     acrossTenants()" -- and as nobody, in the panel it declares or none;
 *   - sits between two runs of the reset contract, as a request does, so
 *     nothing one task reads is the next one's;
 *   - fails alone: a task that throws is reported, and the rest still run.
 */
final readonly class Scheduler
{
    /**
     * @param list<class-string> $tasks
     * @param \Closure(class-string): object $resolver
     * @param (\Closure(\Throwable): void)|null $report
     */
    public function __construct(
        private array $tasks,
        private Orm $orm,
        private \Closure $resolver,
        private RequestScope $scope,
        private ScheduleStore $store,
        private ?PanelContext $panels = null,
        private ?PanelRegistry $registry = null,
        private ?\Closure $report = null,
    ) {
        foreach ($tasks as $task) {
            if (new \ReflectionClass($task)->getAttributes(Schedule::class) === []) {
                throw new \InvalidArgumentException("{$task} is registered as a scheduled task and has no #[Schedule].");
            }
            if (!method_exists($task, '__invoke')) {
                throw new \InvalidArgumentException("{$task} is a scheduled task and has no __invoke() to run.");
            }
            // A panel it declares must exist now, not at 3 a.m. when it is due.
            DeclaredPanel::of($task, $registry);
        }
    }

    /**
     * The tasks due in the minute $now falls in.
     *
     * @return list<class-string>
     */
    public function due(\DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->tasks, static function (string $task) use ($now): bool {
            foreach (new \ReflectionClass($task)->getAttributes(Schedule::class) as $attribute) {
                if ($attribute->newInstance()->cron->matches($now)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Runs every task due in the minute $now falls in that no other
     * scheduler has claimed.
     *
     * @return array<class-string, 'ran'|'failed'|'skipped'> what became of each due task
     */
    public function runDue(\DateTimeImmutable $now): array
    {
        $minute = intdiv($now->getTimestamp(), 60) * 60;
        $outcomes = [];
        foreach ($this->due($now) as $task) {
            if (!$this->store->claim($task, $minute)) {
                $outcomes[$task] = 'skipped';

                continue;
            }
            $outcomes[$task] = $this->run($task);
        }

        return $outcomes;
    }

    /**
     * One task, now, in its context.
     *
     * @param class-string $task
     * @return 'ran'|'failed'
     */
    public function run(string $task): string
    {
        $this->scope->reset();
        try {
            $panel = DeclaredPanel::of($task, $this->registry);
            $invoke = function () use ($task): void {
                $instance = ($this->resolver)($task);
                $arguments = [];
                foreach (new \ReflectionMethod($instance, '__invoke')->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                        throw new \LogicException("{$task}::__invoke() takes \${$parameter->getName()}, which is not a service.");
                    }
                    /** @var class-string $class */
                    $class = $type->getName();
                    $arguments[] = ($this->resolver)($class);
                }
                $callable = [$instance, '__invoke'];
                \assert(\is_callable($callable));
                $callable(...$arguments);
            };

            if ($this->orm->tenant() !== '') {
                throw new \LogicException('A scheduled task runs with no tenant, and the reset left one in force.');
            }
            if ($panel !== null) {
                if ($this->panels === null) {
                    throw new \LogicException("{$task} is in panel '{$panel->name}', and the scheduler was given no PanelContext.");
                }
                $this->panels->within($panel->name, $invoke);
            } else {
                $invoke();
            }

            return 'ran';
        } catch (\Throwable $e) {
            if ($this->report !== null) {
                ($this->report)($e);
            }

            return 'failed';
        } finally {
            $this->scope->reset();
        }
    }

    /** @return list<array{task: class-string, schedules: list<string>}> */
    public function tasks(): array
    {
        return array_map(static fn (string $task): array => [
            'task' => $task,
            'schedules' => array_map(
                static fn (\ReflectionAttribute $a): string => $a->newInstance()->expression,
                new \ReflectionClass($task)->getAttributes(Schedule::class),
            ),
        ], $this->tasks);
    }
}
