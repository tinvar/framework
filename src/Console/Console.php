<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\RequestScope;
use Tinvar\Jobs\DeclaredPanel;
use Tinvar\Jobs\JobRunner;
use Tinvar\Orm\Orm;
use Tinvar\Queue\Queue;
use Tinvar\Queue\Worker;
use Tinvar\Queue\WorkerOptions;

/**
 * An application's console: its own #[Command] classes, and the framework's
 * commands for its queue and its schedule.
 *
 *   queue:work [options]   run jobs until stopped (see WorkerOptions)
 *   queue:failed           list failed jobs
 *   queue:retry <id|all>   put failed jobs back on their queues
 *   schedule:run           run the tasks due this minute, for cron
 *   schedule:work          run the schedule every minute until stopped
 *   schedule:list          every task and when it runs
 *
 * An application command runs in the context it declares and no other: the
 * tenant its `--tenant=` names when it declares
 * #[TenantFrom(TenantArgument::class)], checked against the application's
 * TenantDirectory -- and no tenant otherwise, with `--tenant` refused, so
 * nobody passes one believing a command is scoped when it is not -- the
 * panel its #[Panel] or namespace puts it in, and nobody signed in. The
 * reset contract runs before and after it.
 */
final class Console
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    private ?Worker $worker = null;

    private bool $stopping = false;

    /**
     * @param list<class-string<Command>> $commands
     * @param \Closure(class-string): object $resolver builds commands and what they take
     * @param (\Closure(\Throwable): void)|null $report a failure, for the application's log
     * @param (\Closure(): \DateTimeImmutable)|null $clock now; the system clock unless given
     */
    public function __construct(
        array $commands,
        private readonly Orm $orm,
        private readonly \Closure $resolver,
        private readonly RequestScope $scope,
        private readonly ?PanelContext $panels = null,
        private readonly ?PanelRegistry $registry = null,
        private readonly ?TenantDirectory $tenants = null,
        private readonly ?Queue $queue = null,
        private readonly ?JobRunner $runner = null,
        private readonly ?Scheduler $scheduler = null,
        private readonly ?\Closure $report = null,
        private readonly ?\Closure $clock = null,
    ) {
        foreach ($commands as $class) {
            $attribute = new \ReflectionClass($class)->getAttributes(CommandName::class)[0] ?? null;
            if ($attribute === null || !is_subclass_of($class, Command::class)) {
                throw new \InvalidArgumentException("{$class} is registered as a command, and is not a Command with a #[Command] name.");
            }
            $name = $attribute->newInstance()->name;
            if (isset($this->commands[$name]) || \in_array($name, self::BUILT_IN, true)) {
                throw new \InvalidArgumentException("Two commands are named '{$name}'.");
            }
            self::checkTenantSource($class);
            DeclaredPanel::of($class, $registry);
            $this->commands[$name] = $class;
        }
    }

    private const array BUILT_IN = ['queue:work', 'queue:failed', 'queue:retry', 'schedule:run', 'schedule:work', 'schedule:list'];

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || \in_array($name, self::BUILT_IN, true);
    }

    /** @return array<string, string> every command's name and what it does */
    public function describe(): array
    {
        $described = [];
        foreach ($this->commands as $name => $class) {
            $attribute = new \ReflectionClass($class)->getAttributes(CommandName::class)[0];
            $described[$name] = $attribute->newInstance()->description;
        }

        return $described;
    }

    /** @param list<string> $arguments */
    public function run(string $name, array $arguments, ?Output $output = null): int
    {
        $output ??= Output::standard();

        try {
            return match ($name) {
                'queue:work' => $this->work($arguments, $output),
                'queue:failed' => $this->failed($output),
                'queue:retry' => $this->retry($arguments, $output),
                'schedule:run' => $this->scheduleRun($output),
                'schedule:work' => $this->scheduleWork($output),
                'schedule:list' => $this->scheduleList($output),
                default => $this->command($name, Input::parse($arguments), $output),
            };
        } catch (\InvalidArgumentException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }
    }

    /** Asks a running queue:work or schedule:work to stop once the work in hand is done. */
    public function stop(): void
    {
        $this->stopping = true;
        $this->worker?->stop();
    }

    private function command(string $name, Input $input, Output $output): int
    {
        $class = $this->commands[$name] ?? throw new \InvalidArgumentException("No command '{$name}'.");

        $tenant = '';
        if (TenantFrom::declaredOn($class) === TenantArgument::class) {
            $tenant = $input->option(TenantArgument::OPTION) ?? throw new \InvalidArgumentException(
                "{$name} runs as a tenant: name it with --" . TenantArgument::OPTION . '=ID.',
            );
            if ($this->tenants === null) {
                throw new \LogicException("{$name} runs as the tenant its argument names, and the console was given no TenantDirectory to check it against.");
            }
            if ($tenant === '' || !$this->tenants->has($tenant)) {
                throw new \InvalidArgumentException("There is no tenant '{$tenant}'.");
            }
            $input = $input->without(TenantArgument::OPTION);
        } elseif ($input->option(TenantArgument::OPTION) !== null) {
            throw new \InvalidArgumentException("{$name} does not run as a tenant, so --" . TenantArgument::OPTION . ' would scope nothing.');
        }

        $panel = DeclaredPanel::of($class, $this->registry);

        $this->scope->reset();
        try {
            $run = function () use ($class, $input, $output): int {
                $command = ($this->resolver)($class);
                \assert($command instanceof Command);

                return $command->handle($input, $output);
            };
            $inPanel = function () use ($panel, $run, $name): int {
                if ($panel === null) {
                    return $run();
                }
                if ($this->panels === null) {
                    throw new \LogicException("{$name} is in panel '{$panel->name}', and the console was given no PanelContext.");
                }

                return $this->panels->within($panel->name, $run);
            };

            return $this->orm->actAs($tenant, $inPanel);
        } catch (\Throwable $e) {
            $this->reportFailure($e);
            $output->error($e::class . ': ' . $e->getMessage());

            return 1;
        } finally {
            $this->scope->reset();
        }
    }

    /** @param list<string> $arguments */
    private function work(array $arguments, Output $output): int
    {
        [$queue, $runner] = $this->queueParts();
        $options = WorkerOptions::fromArguments($arguments);

        $this->worker = new Worker(
            $queue,
            $runner,
            $this->scope,
            log: static fn (string $line) => $output->error('[' . gmdate('Y-m-d H:i:s') . '] ' . $line),
            report: $this->report,
        );
        $output->error('Working ' . implode(', ', $options->queues) . '. Ctrl+C stops after the job in hand.');
        $this->worker->run($options);

        return 0;
    }

    private function failed(Output $output): int
    {
        [$queue] = $this->queueParts();
        foreach ($queue->failed() as $failure) {
            $output->line(\sprintf(
                '%s  %s  %s  %s',
                $failure->id,
                $failure->queue,
                gmdate('Y-m-d H:i:s', $failure->failedAt),
                strtok($failure->error, "\n") ?: '',
            ));
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private function retry(array $arguments, Output $output): int
    {
        [$queue] = $this->queueParts();
        $ids = $arguments === ['all']
            ? array_map(static fn ($failure): string => $failure->id, $queue->failed())
            : $arguments;
        if ($ids === []) {
            throw new \InvalidArgumentException('Usage: tinvar queue:retry <id>... | all');
        }

        $status = 0;
        foreach ($ids as $id) {
            if ($queue->retry($id, time())) {
                $output->line("retried {$id}");
            } else {
                $output->error("No failed job {$id}.");
                $status = 1;
            }
        }

        return $status;
    }

    private function scheduleRun(Output $output): int
    {
        $failed = false;
        foreach ($this->schedulerOrFail()->runDue($this->now()) as $task => $outcome) {
            $output->line("{$outcome}  {$task}");
            $failed = $failed || $outcome === 'failed';
        }

        return $failed ? 1 : 0;
    }

    private function scheduleWork(Output $output): int
    {
        $scheduler = $this->schedulerOrFail();
        $this->stopping = false;
        $this->trapSignals();
        $output->error('Running the schedule every minute. Ctrl+C stops after the task in hand.');

        $last = null;
        while (!$this->stopRequested()) {
            $now = $this->now();
            $minute = intdiv($now->getTimestamp(), 60);
            if ($minute !== $last) {
                $last = $minute;
                foreach ($scheduler->runDue($now) as $task => $outcome) {
                    $output->error('[' . gmdate('Y-m-d H:i:s') . "] {$outcome}  {$task}");
                }
            }
            // A tenth of a second at a time, so a stop is not kept waiting.
            usleep(100_000);
        }

        return 0;
    }

    private function scheduleList(Output $output): int
    {
        foreach ($this->schedulerOrFail()->tasks() as $task) {
            $output->line(implode(' | ', $task['schedules']) . '  ' . $task['task']);
        }

        return 0;
    }

    /** @return array{Queue, JobRunner} */
    private function queueParts(): array
    {
        if ($this->queue === null || $this->runner === null) {
            throw new \LogicException('This application has no queue: the console was given no Queue and JobRunner.');
        }

        return [$this->queue, $this->runner];
    }

    private function schedulerOrFail(): Scheduler
    {
        return $this->scheduler ?? throw new \LogicException('This application has no schedule: the console was given no Scheduler.');
    }

    /**
     * Only a command argument places a command: Host and Signature read a
     * request, and a command has none, so one declaring either would run
     * with no tenant while its author believed otherwise.
     *
     * @param class-string $class
     */
    private static function checkTenantSource(string $class): void
    {
        $source = TenantFrom::declaredOn($class);
        if ($source !== null && $source !== TenantArgument::class) {
            throw new \InvalidArgumentException(
                "{$class} declares its tenant from {$source}, which reads a request; a command's comes from its argument: #[TenantFrom(TenantArgument::class)].",
            );
        }
    }

    private function trapSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopping = true;
            });
        }
    }

    /** @phpstan-impure */
    private function stopRequested(): bool
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stopping;
    }

    private function reportFailure(\Throwable $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : ($this->clock)();
    }
}
