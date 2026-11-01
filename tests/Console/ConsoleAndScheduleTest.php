<?php

declare(strict_types=1);

/**
 * Commands and scheduled tasks: the two contexts with no request behind
 * them, and the tenant and panel each runs in.
 *
 * "The route, webhook, command or job declares where its tenant comes
 * from: ... or a command argument." "Scheduled tasks and migrations run
 * with no tenant, so tenant-scoped work in them must use forEachTenant()
 * or acrossTenants()." "Every non-HTTP context (... consoles) has a defined
 * panel" (tinvar-spec.md).
 *
 * With nothing of tinvar/tenancy installed: a command declaring a tenant is
 * placed by whatever tenancy is registered (CommandTenants), here a
 * stand-in, and refused with none (ADR 0056). TenantArgument, the
 * directory and forEachTenant() are tested with tenancy.
 */

namespace Tinvar\Tests\Console\ConsoleAndSchedule;

use Tinvar\Attributes\Command as CommandName;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Schedule;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Console\ArrayScheduleStore;
use Tinvar\Console\Command;
use Tinvar\Console\CommandTenants;
use Tinvar\Console\Console;
use Tinvar\Console\Cron;
use Tinvar\Console\DatabaseScheduleStore;
use Tinvar\Console\Input;
use Tinvar\Console\Output;
use Tinvar\Console\Scheduler;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\Script;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

/** A source a command declares, which only the registered tenancy gives a meaning. */
final class FromArgument {}

/**
 * The framework suite's stand-in for tinvar/tenancy's console half: places
 * a command declaring FromArgument in the tenant `--tenant=` names, if it is
 * acme or globex -- or, told to, in none, which the console refuses.
 */
final class Commands implements CommandTenants
{
    public function __construct(private readonly ?string $answer = null) {}

    public function checkCommand(string $command, string $source): void
    {
        if ($source !== FromArgument::class) {
            throw new \InvalidArgumentException("{$command} declares its tenant from {$source}, which reads a request.");
        }
    }

    public function commandTenant(string $name, string $source, ?string $named): string
    {
        if ($this->answer !== null) {
            return $this->answer;
        }
        if (!\in_array($named, ['acme', 'globex'], true)) {
            throw new \InvalidArgumentException("There is no tenant '{$named}'.");
        }

        return $named;
    }
}

/** Lists the tenant's scripts: tenant-scoped work, so it needs a tenant. */
#[CommandName('scripts:list', description: 'The tenant\'s scripts'), TenantFrom(FromArgument::class)]
final class ListScripts extends Command
{
    public function __construct(private readonly Orm $orm, private readonly AuthContext $auth) {}

    public function handle(Input $input, Output $output): int
    {
        foreach ($this->orm->all(Script::class, 'name') as $script) {
            $output->line($script->name);
        }
        $output->line('user: ' . ($this->auth->id() ?? 'nobody'));
        $output->line('extra: ' . ($input->argument(0) ?? '-'));

        return 0;
    }
}

#[CommandName('reports:staff'), Panel('admin')]
final class StaffReport extends Command
{
    public function __construct(private readonly PanelContext $panels, private readonly Orm $orm) {}

    public function handle(Input $input, Output $output): int
    {
        $output->line("panel {$this->panels->current()}, tenant '{$this->orm->tenant()}'");

        return 0;
    }
}

#[CommandName('scripts:count')]
final class CountScripts extends Command
{
    public function __construct(private readonly Orm $orm) {}

    public function handle(Input $input, Output $output): int
    {
        $output->line((string) \count($this->orm->all(Script::class)));

        return 0;
    }
}

/** Every ten minutes: says which tenant it ran as. */
#[Schedule('*/10 * * * *')]
final class RecordsTenant
{
    public function __invoke(Orm $orm, Journal $journal): void
    {
        $journal->entries[] = ['task tenant' => $orm->tenant()];
    }
}

/** At 03:05 daily, in the admin panel, and it fails. */
#[Schedule('5 3 * * *'), Panel('admin')]
final class NightlyFails
{
    public function __invoke(PanelContext $panels, Journal $journal): void
    {
        $journal->entries[] = ['nightly panel' => $panels->current()];

        throw new \RuntimeException('nightly failed on purpose');
    }
}

/** On the hour, and every ten minutes: says when it ran. */
#[Schedule('0 * * * *')]
final class Hourly
{
    public function __invoke(Journal $journal): void
    {
        $journal->entries[] = ['ran' => 'hourly'];
    }
}

#[Schedule('*/10 * * * *')]
final class EveryTenMinutes
{
    public function __invoke(Journal $journal): void
    {
        $journal->entries[] = ['ran' => 'every ten minutes'];
    }
}

/** Reads a tenant-scoped model with none: refused, as the spec has it. */
#[Schedule('* * * * *')]
final class ForgetsTheTenant
{
    public function __invoke(Orm $orm): void
    {
        $orm->all(Script::class);
    }
}

/** @return array{Console, Output, Harness, \Closure(): array{list<string>, list<string>}} */
function console(?Harness $h = null, ?CommandTenants $tenants = new Commands()): array
{
    $h ??= new Harness();
    $out = [];
    $err = [];
    $output = new Output(static function (string $line) use (&$out): void {
        $out[] = $line;
    }, static function (string $line) use (&$err): void {
        $err[] = $line;
    });
    $console = new Console(
        [ListScripts::class, StaffReport::class, CountScripts::class],
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        $h->panels,
        $h->registry,
        $tenants,
        $h->queue,
        $h->runner,
    );

    return [$console, $output, $h, function () use (&$out, &$err): array {
        return [$out, $err];
    }];
}

it('runs a command as the tenant its --tenant names, and as nobody', function () {
    [$console, $output, $h, $read] = console();
    $h->auth->login(new \Tinvar\Tests\Jobs\Fixtures\User('left-over'));

    expect($console->run('scripts:list', ['--tenant=acme', 'more'], $output))->toBe(0)
        ->and($read()[0])->toBe(['acme-script-1', 'acme-script-2', 'user: nobody', 'extra: more'])
        // The tenant was the command's for its run alone.
        ->and($h->orm->tenant())->toBe('');
});

it('refuses a command run with a tenant the tenancy refuses, or with one it does not use', function () {
    [$console, $output, , $read] = console();

    expect($console->run('scripts:list', ['--tenant=initech'], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:count', ['--tenant=acme'], $output))->toBe(Console::USAGE)
        ->and($read()[1])->toBe([
            "There is no tenant 'initech'.",
            'scripts:count does not run as a tenant, so --tenant would scope nothing.',
        ]);
});

// The console's own check, so it holds with any tenancy: a command that
// declares a tenant runs as one, never as none.
it('refuses a tenancy\'s answer that places a command in no tenant', function () {
    [$console, $output, , $read] = console(tenants: new Commands(answer: ''));

    expect($console->run('scripts:list', ['--tenant=acme'], $output))->toBe(Console::USAGE)
        ->and($read()[1])->toBe(['scripts:list runs as a tenant: name it with --tenant=ID.']);
});

// Written with a space, as most command lines take an option's value,
// `--tenant` was read as a flag and the tenant as an argument, and a
// command declaring none ran over everything while whoever typed it
// believed it held to one tenant.
it('refuses --tenant written with a space on a command that runs as no tenant', function () {
    [$console, $output, , $read] = console();

    expect($console->run('reports:staff', ['--tenant', 'acme'], $output))->toBe(Console::USAGE)
        ->and($read())->toBe([[], ['reports:staff does not run as a tenant, so --tenant would scope nothing.']]);
});

it('runs a command declaring no tenant with none, so tenant-scoped reads fail closed', function () {
    [$console, $output, , $read] = console();

    expect($console->run('scripts:count', [], $output))->toBe(1)
        ->and($read()[1][0])->toContain(NoTenantContext::class);
});

it('runs a command in the panel it declares', function () {
    [$console, $output, $h, $read] = console();

    expect($console->run('reports:staff', [], $output))->toBe(0)
        ->and($read()[0])->toBe(["panel admin, tenant ''"])
        ->and($h->panels->currentOrNull())->toBeNull();
});

// ADR 0056: what a tenant source is, and which tenants there are, is the
// registered tenancy's; with none, a command declaring one is refused when
// the console is built, rather than run as no tenant.
it('refuses a command declaring a tenant source with no tenancy to place it', function () {
    $h = new Harness();

    expect(fn () => console($h, tenants: null))->toThrow(\InvalidArgumentException::class, 'no tenancy to place it')
        ->and(fn () => new Console([ListScripts::class], $h->orm, $h->container->resolver(), $h->scope, tenants: new Commands()))->not->toThrow(\Throwable::class);
});

it('refuses tenancy given to the console and not to the Orm', function () {
    $h = new Harness();
    $bare = new Orm($h->fixture->connection);

    expect(fn () => new Console([], $bare, $h->container->resolver(), $h->scope, tenants: new Commands()))
        ->toThrow(\InvalidArgumentException::class, 'its Orm none');
});

it('works the queue from the console until it is empty', function () {
    [$console, $output, $h] = console();
    // The console's worker keeps the system's time, so the job is
    // dispatched by it too.
    $h->now = time();
    $h->dispatcher->dispatch(new \Tinvar\Tests\Jobs\Fixtures\RecordContext(note: 'console'));

    expect($console->run('queue:work', ['--stop-when-empty'], $output))->toBe(0)
        ->and($h->journal()->entries[0]['note'])->toBe('console');
});

it('matches cron expressions in UTC, steps, ranges, lists and either day field', function () {
    $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);

    expect(Cron::parse('*/10 * * * *')->matches($at('2026-10-24T10:20:00Z')))->toBeTrue()
        ->and(Cron::parse('*/10 * * * *')->matches($at('2026-10-24T10:21:00Z')))->toBeFalse()
        ->and(Cron::parse('0 3 * * *')->matches($at('2026-10-24T05:00:00+02:00')))->toBeTrue()
        ->and(Cron::parse('0-30/15 9-17 * * 1-5')->matches($at('2026-10-23T09:30:00Z')))->toBeTrue('a Friday')
        ->and(Cron::parse('0-30/15 9-17 * * 1-5')->matches($at('2026-10-24T09:30:00Z')))->toBeFalse('a Saturday')
        ->and(Cron::parse('0 0 1 * 7')->matches($at('2026-10-25T00:00:00Z')))->toBeTrue('a Sunday, by either field')
        ->and(Cron::parse('5,35 * * * *')->matches($at('2026-10-24T10:35:00Z')))->toBeTrue()
        ->and(fn () => Cron::parse('* * * *'))->toThrow(\InvalidArgumentException::class, 'five fields')
        ->and(fn () => Cron::parse('60 * * * *'))->toThrow(\InvalidArgumentException::class, 'outside');
});

it('runs due tasks with no tenant, in their panel, once a minute', function () {
    $h = new Harness();
    $reports = [];
    $scheduler = new Scheduler(
        [RecordsTenant::class, NightlyFails::class],
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        new ArrayScheduleStore(),
        $h->panels,
        $h->registry,
        static function (\Throwable $e) use (&$reports): void {
            $reports[] = $e->getMessage();
        },
    );

    // A worker left running as a tenant: the task still runs as none.
    $h->orm->switchTenant('acme');
    expect($scheduler->due(new \DateTimeImmutable('2026-10-24T10:20:00Z')))->toBe([RecordsTenant::class])
        ->and($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:30Z')))->toBe([RecordsTenant::class => 'ran'])
        ->and($h->journal()->entries)->toBe([['task tenant' => '']]);

    // Due again the same minute -- a second scheduler, or a cron overlapping
    // schedule:work -- it does not run twice.
    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:50Z')))->toBe([RecordsTenant::class => 'skipped']);

    // A task that throws is reported; it ran in its panel.
    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T03:05:00Z')))->toBe([NightlyFails::class => 'failed'])
        ->and($reports)->toBe(['nightly failed on purpose'])
        ->and(end($h->journal()->entries))->toBe(['nightly panel' => 'admin'])
        ->and($h->panels->currentOrNull())->toBeNull();
});

it('fails closed in a task that reads tenant data without forEachTenant()', function () {
    $h = new Harness();
    $reports = [];
    $scheduler = new Scheduler([ForgetsTheTenant::class], $h->orm, $h->container->resolver(), $h->scope, new ArrayScheduleStore(), report: static function (\Throwable $e) use (&$reports): void {
        $reports[] = $e::class;
    });

    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:21:00Z')))->toBe([ForgetsTheTenant::class => 'failed'])
        ->and($reports)->toBe([NoTenantContext::class]);
});

it('claims a task\'s minute once across schedulers sharing a database', function () {
    $h = new Harness();
    DatabaseScheduleStore::define(schemaOf($h->fixture->connection));
    $store = new DatabaseScheduleStore($h->fixture->connection);
    $other = new DatabaseScheduleStore($h->fixture->connection);

    expect($store->claim('Task', 600))->toBeTrue()
        ->and($other->claim('Task', 600))->toBeFalse()
        ->and($other->claim('Task', 660))->toBeTrue()
        ->and($store->claim('Other', 600))->toBeTrue();
});

// schedule:work looked at the minute it turned to and no other: a task that
// ran past its minute held the loop past the next ones, and a task due in
// one of them was skipped without a word.
describe('minutes a scheduler was held past', function () {
    $scheduler = static fn (Harness $h, ?ArrayScheduleStore $store = null): Scheduler => new Scheduler(
        [Hourly::class, EveryTenMinutes::class],
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        $store ?? new ArrayScheduleStore(),
    );
    $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);

    it('runs a task due in one of them late, rather than not at all', function () use ($scheduler, $at) {
        $h = new Harness();

        expect($scheduler($h)->runSince($at('2026-10-24T10:09:30Z'), $at('2026-10-24T10:11:05Z')))->toBe([EveryTenMinutes::class => 'ran'])
            ->and($h->journal()->entries)->toBe([['ran' => 'every ten minutes']]);
    });

    it('runs each task once however many of its minutes went by, claimed under the last', function () use ($scheduler, $at) {
        $h = new Harness();
        $store = new ArrayScheduleStore();

        expect($scheduler($h, $store)->runSince($at('2026-10-24T09:55:00Z'), $at('2026-10-24T11:02:00Z')))
            ->toBe([Hourly::class => 'ran', EveryTenMinutes::class => 'ran'])
            ->and($h->journal()->entries)->toHaveCount(2)
            // A scheduler that looked at 11:00 in time claimed it, as this
            // one did: neither runs it a second time.
            ->and($store->claim(Hourly::class, $at('2026-10-24T11:00:00Z')->getTimestamp()))->toBeFalse()
            ->and($scheduler($h, $store)->runSince($at('2026-10-24T10:59:00Z'), $at('2026-10-24T11:00:30Z')))
            ->toBe([Hourly::class => 'skipped', EveryTenMinutes::class => 'skipped']);
    });

    it('looks at no minute it looked at already, nor back past a day', function () use ($scheduler, $at) {
        $h = new Harness();

        expect($scheduler($h)->runSince($at('2026-10-24T10:10:30Z'), $at('2026-10-24T10:10:50Z')))->toBe([])
            ->and($scheduler($h)->runSince($at('2026-10-24T10:11:00Z'), $at('2026-10-24T10:09:00Z')))->toBe([])
            ->and($scheduler($h)->runSince($at('2026-10-20T10:00:00Z'), $at('2026-10-24T10:05:00Z')))
            ->toBe([Hourly::class => 'ran', EveryTenMinutes::class => 'ran']);
    });

    it('is what schedule:work runs each time the minute turns', function () use ($scheduler, $at) {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('schedule:work traps signals with pcntl.');
        }
        $h = new Harness();
        $times = [$at('2026-10-24T10:09:30Z'), $at('2026-10-24T10:11:05Z')];
        $console = null;
        $clock = static function () use (&$times, &$console): \DateTimeImmutable {
            $now = array_shift($times) ?? throw new \LogicException('asked the time after it was told to stop');
            if ($times === []) {
                \assert($console instanceof Console);
                $console->stop();
            }

            return $now;
        };
        $console = new Console([], $h->orm, $h->container->resolver(), $h->scope, scheduler: $scheduler($h), clock: $clock);

        try {
            expect($console->run('schedule:work', [], new Output(static function (string $line): void {}, static function (string $line): void {})))->toBe(0)
                ->and($h->journal()->entries)->toBe([['ran' => 'every ten minutes']]);
        } finally {
            // The console trapped them for its loop; this process is a test runner's.
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }
            pcntl_async_signals(false);
        }
    });
});

// queue:retry made a job available at the system clock's now, past the
// clock the console was given; and a built-in asked of an application with
// no queue or schedule threw its LogicException up as a crash.
it('retries a failed job as of the console\'s clock, and says plainly when the application has no queue', function () {
    $h = new Harness();
    $h->queue->push(new \Tinvar\Queue\PendingJob('j1', 'default', 'payload', 100));
    $reserved = $h->queue->reserve(['default'], 100, 60);
    \assert($reserved !== null);
    $h->queue->fail($reserved, 'boom', 100);
    $at = 2_000_000_000;
    $err = [];
    $output = new Output(static function (string $line): void {}, static function (string $line) use (&$err): void {
        $err[] = $line;
    });
    $console = new Console(
        [],
        $h->orm,
        $h->container->resolver(),
        $h->scope,
        queue: $h->queue,
        runner: $h->runner,
        clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable("@{$at}"),
    );

    expect($console->run('queue:retry', ['j1'], $output))->toBe(0)
        ->and($h->queue->reserve(['default'], $at - 1, 60))->toBeNull()
        ->and($h->queue->reserve(['default'], $at, 60)?->id)->toBe('j1');

    $bare = new Console([], $h->orm, $h->container->resolver(), $h->scope);
    expect($bare->run('queue:failed', [], $output))->toBe(1)
        ->and($bare->run('schedule:list', [], $output))->toBe(1)
        ->and($err)->toBe(['This application has no queue: the console was given no Queue and JobRunner.', 'This application has no schedule: the console was given no Scheduler.']);
});

// A failed job's stored error is whatever its exception said -- text an
// attacker may have put there -- and queue:failed printed it to the
// terminal as it was, escape sequences and all.
it('lists failed jobs with the control characters in their stored error shown, not sent to the terminal', function () {
    $h = new Harness();
    $h->queue->push(new \Tinvar\Queue\PendingJob('j1', 'default', 'payload', 100));
    $reserved = $h->queue->reserve(['default'], 100, 60);
    \assert($reserved !== null);
    $h->queue->fail($reserved, "boom \e]52;c;Y3VybA==\x07\e[2J\rgone\nsecond line", 100);
    $out = [];
    $output = new Output(static function (string $line) use (&$out): void {
        $out[] = $line;
    }, static function (string $line): void {});
    $console = new Console([], $h->orm, $h->container->resolver(), $h->scope, queue: $h->queue, runner: $h->runner);

    expect($console->run('queue:failed', [], $output))->toBe(0)
        ->and($out)->toHaveCount(1)
        ->and($out[0])->toContain('j1  default')->toEndWith('boom ␛]52;c;Y3VybA==␇␛[2J␍gone')->not->toContain("\e");
});
