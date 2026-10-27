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
use Tinvar\Console\Console;
use Tinvar\Console\Cron;
use Tinvar\Console\DatabaseScheduleStore;
use Tinvar\Console\Input;
use Tinvar\Console\Output;
use Tinvar\Console\Scheduler;
use Tinvar\Tenancy\TenantArgument;
use Tinvar\Tenancy\TenantDirectory;
use Tinvar\Tenancy\Tenants;
use Tinvar\Http\Request;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\Script;

use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

final class TwoTenants implements TenantDirectory
{
    public function has(string $tenant): bool
    {
        return \in_array($tenant, ['acme', 'globex'], true);
    }

    public function all(): iterable
    {
        return ['acme', 'globex'];
    }
}

/** Lists the tenant's scripts: tenant-scoped work, so it needs a tenant. */
#[CommandName('scripts:list', description: 'The tenant\'s scripts'), TenantFrom(TenantArgument::class)]
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

#[CommandName('wrong:source'), TenantFrom(Host::class)]
final class HostCommand extends Command
{
    public function handle(Input $input, Output $output): int
    {
        return 0;
    }
}

/** Every ten minutes: counts each tenant's scripts, one tenant at a time. */
#[Schedule('*/10 * * * *')]
final class CountEachTenant
{
    public function __invoke(Tenants $tenants, Orm $orm, Journal $journal): void
    {
        $journal->entries[] = ['task tenant' => $orm->tenant()];
        $tenants->forEachTenant(function (string $tenant) use ($orm, $journal): void {
            $journal->entries[] = [$tenant => array_map(static fn (Script $s): string => $s->name, $orm->all(Script::class, 'name'))];
        });
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
function console(?Harness $h = null): array
{
    $h ??= new Harness();
    $h->container->instance(Tenants::class, new Tenants($h->orm, new TwoTenants()));
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
        new Tenancy($h->container->resolver(), new TwoTenants()),
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

it('refuses a command run without its tenant, with an unknown one, or with one it does not use', function () {
    [$console, $output, , $read] = console();

    expect($console->run('scripts:list', [], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:list', ['--tenant=initech'], $output))->toBe(Console::USAGE)
        ->and($console->run('scripts:count', ['--tenant=acme'], $output))->toBe(Console::USAGE)
        ->and($read()[1])->toBe([
            'scripts:list runs as a tenant: name it with --tenant=ID.',
            "There is no tenant 'initech'.",
            'scripts:count does not run as a tenant, so --tenant would scope nothing.',
        ]);
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

it('refuses a command whose tenant would come from a request', function () {
    $h = new Harness();

    expect(fn () => new Console([HostCommand::class], $h->orm, $h->container->resolver(), $h->scope, tenants: new Tenancy($h->container->resolver(), new TwoTenants())))
        ->toThrow(\InvalidArgumentException::class, 'reads a request');
});

// ADR 0056: what a tenant source is, and which tenants there are, is the
// registered tenancy's; with none, a command declaring one is refused when
// the console is built, rather than run as no tenant.
it('refuses a command declaring a tenant source with no tenancy to place it, or no directory to check it against', function () {
    $h = new Harness();

    expect(fn () => new Console([ListScripts::class], $h->orm, $h->container->resolver(), $h->scope))
        ->toThrow(\InvalidArgumentException::class, 'no tenancy to place it')
        ->and(fn () => new Console([ListScripts::class], $h->orm, $h->container->resolver(), $h->scope, tenants: new Tenancy($h->container->resolver())))
        ->toThrow(\InvalidArgumentException::class, 'no TenantDirectory');
});

it('places no request with a command argument, so on a page it fails closed', function () {
    expect(new TenantArgument()->tenantFor(Request::get('/')))->toBeNull();
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

it('runs due tasks with no tenant, forEachTenant() visiting each tenant in turn', function () {
    $h = new Harness();
    $h->container->instance(Tenants::class, new Tenants($h->orm, new TwoTenants()));
    $reports = [];
    $scheduler = new Scheduler(
        [CountEachTenant::class, NightlyFails::class],
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
    expect($scheduler->due(new \DateTimeImmutable('2026-10-24T10:20:00Z')))->toBe([CountEachTenant::class])
        ->and($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:30Z')))->toBe([CountEachTenant::class => 'ran'])
        ->and($h->journal()->entries)->toBe([
            ['task tenant' => ''],
            ['acme' => ['acme-script-1', 'acme-script-2']],
            ['globex' => ['globex-script-1', 'globex-script-2']],
        ]);

    // Due again the same minute -- a second scheduler, or a cron overlapping
    // schedule:work -- it does not run twice.
    expect($scheduler->runDue(new \DateTimeImmutable('2026-10-24T10:20:50Z')))->toBe([CountEachTenant::class => 'skipped']);

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

it('refuses forEachTenant() from work already running as a tenant', function () {
    $h = new Harness();
    $tenants = new Tenants($h->orm, new TwoTenants());

    expect(fn () => $h->orm->actAs('acme', fn () => $tenants->forEachTenant(static fn () => null)))
        ->toThrow(\LogicException::class, 'would reach every other tenant');
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
