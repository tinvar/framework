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
use Tinvar\Jobs\Worker;
use Tinvar\Observability\Telemetry;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Orm\Orm;
use Tinvar\Jobs\Rate;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\Queue;
use Tinvar\Jobs\StopBudget;
use Tinvar\Queue\HeldJob;
use Tinvar\Support\KnownAttributes;

/**
 * An application's console: its own #[Command] classes, and the framework's
 * commands for its queue and its schedule.
 *
 *   queue:work [options]   run jobs until stopped (see WorkerOptions)
 *   queue:failed           list failed jobs
 *   queue:retry <id|all>   put failed jobs back on their queues
 *   queue:hand-back <file> put back the job a killed worker held (HeldJob), for serve
 *   queue:prune [--days=N] forget batches finished N days ago (7) and lapsed unique locks
 *   schedule:run           run the tasks due this minute, for cron
 *   schedule:work          run the schedule every minute until stopped
 *   schedule:list          every task and when it runs
 *
 * An application command runs in the context it declares and no other: the
 * tenant its `--tenant=` names when it declares a tenant source, as the
 * registered tenancy reads and checks it (CommandTenants; tinvar/tenancy's
 * TenantArgument) -- and no tenant otherwise, with `--tenant` refused, so
 * nobody passes one believing a command is scoped when it is not -- the
 * panel its #[Panel] or namespace puts it in, and nobody signed in. The
 * reset contract runs before and after it.
 */
final class Console
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** The option a command's tenant is named by: `--tenant=ID`. */
    public const string TENANT_OPTION = 'tenant';

    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    private ?Worker $worker = null;

    private bool $stopping = false;

    /**
     * @param list<class-string<Command>> $commands
     * @param \Closure(class-string): object $resolver builds commands and what they take
     * @param (\Closure(\Throwable): void)|null $report a failure, for the application's log
     * @param (\Closure(): \DateTimeImmutable)|null $clock now; the system clock unless given
     * @param list<string> $queues what queue:work works without --queue, in priority order:
     *        every queue the application's jobs are on, or a worker started plainly
     *        leaves some of them unworked
     * @param CommandTenants|null $tenants the tenancy registered (tinvar/tenancy): how a
     *        command declaring #[TenantFrom] is placed in a tenant. Without it, one is
     *        refused here
     * @param array<string, Rate> $rates the rate-limited queues, by name: every worker
     *        queue:work starts holds to them (ADR 0100)
     * @param Telemetry|null $telemetry where each command and each job queue:work runs is
     *        a trace of its own (ADR 0112); the scheduler is given its own
     */
    public function __construct(
        array $commands,
        private readonly Orm $orm,
        private readonly \Closure $resolver,
        private readonly RequestScope $scope,
        private readonly ?PanelContext $panels = null,
        private readonly ?PanelRegistry $registry = null,
        private readonly ?CommandTenants $tenants = null,
        private readonly ?Queue $queue = null,
        private readonly ?JobRunner $runner = null,
        private readonly ?Scheduler $scheduler = null,
        private readonly ?\Closure $report = null,
        private readonly ?\Closure $clock = null,
        private readonly array $queues = [\Tinvar\Attributes\Queue::DEFAULT],
        private readonly array $rates = [],
        private readonly ?Telemetry $telemetry = null,
    ) {
        if ($tenants !== null && !$orm->hasTenancy()) {
            throw new \InvalidArgumentException(
                'The console was given tenancy, and its Orm none: register the tree with the Orm (Tenancy::tree()) too.',
            );
        }

        foreach ($commands as $class) {
            // A Tinvar attribute naming no class would be matched by
            // nothing, and what it declares would not hold (KnownAttributes).
            $reflection = new \ReflectionClass($class);
            KnownAttributes::check($reflection);
            $attribute = $reflection->getAttributes(CommandName::class)[0] ?? null;
            if ($attribute === null || !is_subclass_of($class, Command::class)) {
                throw new \InvalidArgumentException("{$class} is registered as a command, and is not a Command with a #[Command] name.");
            }
            $name = $attribute->newInstance()->name;
            if (isset($this->commands[$name]) || \in_array($name, self::BUILT_IN, true)) {
                throw new \InvalidArgumentException("Two commands are named '{$name}'.");
            }
            $this->checkTenantSource($class);
            DeclaredPanel::of($class, $registry);
            $this->commands[$name] = $class;
        }
    }

    private const array BUILT_IN = ['queue:work', 'queue:failed', 'queue:retry', 'queue:hand-back', 'queue:prune', 'schedule:run', 'schedule:work', 'schedule:list'];

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
            if (!\in_array($name, self::BUILT_IN, true)) {
                return $this->command($name, Input::parse($arguments), $output);
            }

            try {
                return match ($name) {
                    'queue:work' => $this->work($arguments, $output),
                    'queue:failed' => $this->failed($output),
                    'queue:retry' => $this->retry($arguments, $output),
                    'queue:hand-back' => $this->handBack($arguments, $output),
                    'queue:prune' => $this->prune($arguments, $output),
                    'schedule:run' => $this->scheduleRun($output),
                    'schedule:work' => $this->scheduleWork($output),
                    default => $this->scheduleList($output),
                };
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\LogicException $e) {
                // A built-in asked of an application that has none of what
                // it runs -- no queue, no schedule -- says so, as a failure,
                // rather than crashing with a trace. An application's own
                // command's LogicException is its bug, and goes on up.
                $output->error($e->getMessage());

                return 1;
            }
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
        $source = TenantFrom::declaredOn($class);
        if ($source !== null) {
            $tenants = $this->tenants ?? throw new \LogicException("{$name} declares #[TenantFrom({$source})], and there is no tenancy to place it.");
            $tenant = $tenants->commandTenant($name, $source, $input->option(self::TENANT_OPTION));
            // Held here whatever the tenancy answered: a command declaring a
            // tenant runs as one, never as none.
            if ($tenant === '') {
                throw new \InvalidArgumentException("{$name} runs as a tenant: name it with --" . self::TENANT_OPTION . '=ID.');
            }
            $input = $input->without(self::TENANT_OPTION);
        } elseif ($input->option(self::TENANT_OPTION) !== null || $input->flag(self::TENANT_OPTION)) {
            // `--tenant acme`, as most command lines take a value, reads as
            // a flag and an argument: refused like `--tenant=acme`.
            throw new \InvalidArgumentException("{$name} does not run as a tenant, so --" . self::TENANT_OPTION . ' would scope nothing.');
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

            // A command is a trace of its own, named by its name -- never
            // its arguments, which may be an email address or a file.
            return $this->telemetry === null
                ? $this->orm->actAs($tenant, $inPanel)
                : $this->telemetry->trace("command {$name}", ['tinvar.command' => $name], fn (): int => $this->orm->actAs($tenant, $inPanel));
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
        $options = WorkerOptions::fromArguments($arguments, $this->queues, StopBudget::fromEnvironment(), $this->rates);

        $this->worker = new Worker(
            $queue,
            $runner,
            $this->scope,
            log: static fn (string $line) => $output->error('[' . gmdate('Y-m-d H:i:s') . '] ' . $line),
            report: $this->report,
            held: self::heldPath(),
            telemetry: $this->telemetry,
        );
        $output->error('Working ' . implode(', ', $options->queues) . ". Ctrl+C stops after the job in hand, or hands it back within {$options->stopBudget}s.");
        $this->worker->run($options);

        return 0;
    }

    /** Where `tinvar serve` asks the worker to write the job in hand, if it does: an absolute path, or none. */
    private static function heldPath(): ?string
    {
        $path = getenv(HeldJob::ENV);
        if ($path === false || $path === '') {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(HeldJob::ENV . ' must be an absolute path.');
        }

        return $path;
    }

    /**
     * `queue:hand-back FILE`: the job a worker held when it was killed at a
     * stop, back on its queue, available now and its try given back -- if
     * the token in FILE is still the job's. Refused while the worker that
     * wrote it is running: the job would run twice.
     *
     * @param list<string> $arguments
     */
    private function handBack(array $arguments, Output $output): int
    {
        if (\count($arguments) !== 1) {
            throw new \InvalidArgumentException('Usage: tinvar queue:hand-back <file>');
        }
        [$queue] = $this->queueParts();
        $held = HeldJob::read($arguments[0]);
        if ($held === null) {
            return 0;
        }
        if (HeldJob::running($held['pid'])) {
            $output->error("The worker holding {$held['job']->id} (pid {$held['pid']}) is still running; nothing handed back.");

            return 1;
        }

        $handed = $queue->requeue($held['job'], $this->now()->getTimestamp());
        HeldJob::clear($arguments[0]);
        $output->error($handed
            ? "Handed back {$held['job']->id}, which a worker killed at the stop held."
            : "{$held['job']->id} was no longer held by the worker that was killed; nothing handed back.");

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
            if ($queue->retry($id, $this->now()->getTimestamp())) {
                $output->line("retried {$id}");
            } else {
                $output->error("No failed job {$id}.");
                $status = 1;
            }
        }

        return $status;
    }

    /**
     * `queue:prune [--days=N]`: batches finished more than N days ago, 7
     * unless it says, and unique locks already lapsed, forgotten.
     *
     * @param list<string> $arguments
     */
    private function prune(array $arguments, Output $output): int
    {
        [$queue] = $this->queueParts();
        $days = 7;
        foreach ($arguments as $argument) {
            if (preg_match('/^--days=(\d{1,4})\z/', $argument, $m) !== 1) {
                throw new \InvalidArgumentException('Usage: tinvar queue:prune [--days=N]');
            }
            $days = (int) $m[1];
        }
        if (!$queue instanceof Coordination) {
            throw new \LogicException('This application\'s queue keeps no batches or locks to prune.');
        }

        $now = $this->now()->getTimestamp();
        $output->line('pruned ' . $queue->prune($now, $now - $days * 86_400));

        return 0;
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

        // The last time looked at. Each time the minute turns past it, every
        // minute since is looked at, not only the one it turned to: a task
        // that ran long held the loop past the minutes after it, and a task
        // due in one of them was skipped (Scheduler::runSince()). Never back
        // again, should the clock step back.
        $since = null;
        while (!$this->stopRequested()) {
            $now = $this->now();
            if ($since === null || intdiv($now->getTimestamp(), 60) > intdiv($since->getTimestamp(), 60)) {
                $outcomes = $since === null ? $scheduler->runDue($now) : $scheduler->runSince($since, $now);
                $since = $now;
                foreach ($outcomes as $task => $outcome) {
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
     * A command declaring a tenant source needs a tenancy to place it, and
     * one it can run under -- a source that reads a request would leave it
     * with no tenant while its author believed otherwise -- which the
     * tenancy checks.
     *
     * @param class-string $class
     */
    private function checkTenantSource(string $class): void
    {
        $source = TenantFrom::declaredOn($class);
        if ($source === null) {
            return;
        }

        ($this->tenants ?? throw new \InvalidArgumentException(
            "{$class} declares #[TenantFrom({$source})], and the console was given no tenancy to place it: require "
            . 'tinvar/tenancy and give the console its Tenancy.',
        ))->checkCommand($class, $source);
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
