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
    /** How many minutes back runSince() looks for a task that was due: a day's. */
    public const int CATCH_UP_MINUTES = 1440;

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
     * Runs every task due in a minute after $since's, up to and including
     * $now's, that no other scheduler has claimed: what schedule:work does
     * each time the minute turns.
     *
     * Every minute, not only the one it turned to. A task that runs past
     * its minute -- or a process held up some other way -- leaves the
     * minutes it held up unlooked at, and a task due in one of them was
     * skipped without a word: the nightly job missed because the one before
     * it ran long. So it runs late instead, once however many of its
     * minutes went by, claimed under the last of them -- a scheduler that
     * looked at that minute in time has claimed it, and this one skips it.
     * Looked back over CATCH_UP_MINUTES at most.
     *
     * @return array<class-string, 'ran'|'failed'|'skipped'> what became of each task due
     */
    public function runSince(\DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $last = intdiv($now->getTimestamp(), 60);
        $first = max(intdiv($since->getTimestamp(), 60) + 1, $last - self::CATCH_UP_MINUTES + 1);

        /** @var array<class-string, int> $latest each task due, and the last minute it was due in */
        $latest = [];
        for ($minute = $first; $minute <= $last; $minute++) {
            foreach ($this->due($now->setTimestamp($minute * 60)) as $task) {
                $latest[$task] = $minute * 60;
            }
        }

        $outcomes = [];
        foreach ($this->tasks as $task) {
            if (!isset($latest[$task])) {
                continue;
            }
            $outcomes[$task] = $this->store->claim($task, $latest[$task]) ? $this->run($task) : 'skipped';
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
