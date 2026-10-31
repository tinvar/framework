<?php

declare(strict_types=1);

/**
 * When a dispatched job reaches its queue, and what bounds it there.
 *
 * "save(), job dispatch and event dispatch throw inside" loaders and
 * queries (tinvar-spec.md, "Safe reads"). A job dispatched in a transaction
 * reaches a worker only once the transaction commits, and never if it rolls
 * back. A job that runs past its timeout is stopped, and one that will not
 * stop costs the worker its process, not the queue its job.
 */

namespace Tinvar\Tests\Jobs\CommitsAndLimits;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\PanelBinding;
use Tinvar\Data\Data;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Jobs\SyncQueue;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Jobs\Fixtures\Sleeps;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;

require_once __DIR__ . '/Fixtures/jobs.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

final readonly class DispatchedData extends Data
{
    public function __construct(public string $id) {}
}

/** A query body that dispatches a job, which hovering a link would then do. */
#[Query(name: 'jobs.dispatch'), NoAuthorization(reason: 'fixture: exercises the read-only refusal, not access control')]
final class DispatchingQuery
{
    public function __construct(private readonly Dispatcher $jobs) {}

    public function __invoke(): DispatchedData
    {
        return new DispatchedData($this->jobs->dispatch(new RecordContext()));
    }
}

function arrayQueue(Harness $h): ArrayQueue
{
    \assert($h->queue instanceof ArrayQueue);

    return $h->queue;
}

it('refuses dispatch inside a read-only scope, naming it', function () {
    $h = new Harness();

    expect(fn () => $h->orm->readOnly(fn () => $h->dispatcher->dispatch(new RecordContext())))
        ->toThrow(ReadOnlyViolation::class, 'Job dispatch is not allowed inside a loader or query body')
        ->and(arrayQueue($h)->pushed())->toBe([]);
});

it('refuses dispatch from a query body, failing that call alone', function () {
    $h = new Harness();
    $h->container->instance(Dispatcher::class, $h->dispatcher);
    $registry = new QueryRegistry($h->container->resolver());
    $registry->register(DispatchingQuery::class);

    $results = new QueryRunner($registry, $h->orm, new AllowAllGate())->run(
        [['id' => 0, 'name' => 'jobs.dispatch', 'args' => []]],
        ['jobs.dispatch'],
    );

    expect($results[0]['error']['code'] ?? null)->toBe(QueryError::FAILED)
        ->and(arrayQueue($h)->pushed())->toBe([]);
});

it('pushes a job dispatched in a transaction once it commits, and never when it rolls back', function () {
    $h = new Harness();

    $h->orm->transaction(function () use ($h): void {
        $h->dispatcher->dispatch(new RecordContext(note: 'kept'));
        expect(arrayQueue($h)->pushed())->toBe([], 'not before the commit');
    });
    expect(arrayQueue($h)->pushed())->toHaveCount(1);

    try {
        $h->orm->transaction(function () use ($h): void {
            $h->dispatcher->dispatch(new RecordContext(note: 'lost'));

            throw new \RuntimeException('roll back');
        });
    } catch (\RuntimeException) {
    }

    $h->work();
    expect(array_column($h->journal()->entries, 'note'))->toBe(['kept']);
});

it('drops what a nested transaction dispatched when it rolls back, and keeps the outer one\'s', function () {
    $h = new Harness();

    $h->orm->transaction(function () use ($h): void {
        $h->dispatcher->dispatch(new RecordContext(note: 'outer'));
        try {
            $h->orm->transaction(function () use ($h): void {
                $h->dispatcher->dispatch(new RecordContext(note: 'inner'));

                throw new \RuntimeException('inner rolls back');
            });
        } catch (\RuntimeException) {
        }
        $h->orm->transaction(fn () => $h->dispatcher->dispatch(new RecordContext(note: 'inner, kept')));
        expect(arrayQueue($h)->pushed())->toBe([], 'an inner commit is not the commit');
    });

    $h->work();
    expect(array_column($h->journal()->entries, 'note'))->toBe(['outer', 'inner, kept']);
});

/** A harness whose jobs run as they are dispatched, through its own runner. */
function syncHarness(): Harness
{
    $h = new Harness();
    $h->queue = new SyncQueue($h->runner);
    $h->dispatcher = new Dispatcher($h->queue, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry);

    return $h;
}

it('runs a sync job after the commit it waited for, through the same checks a worker makes', function () {
    $h = syncHarness();
    $ran = [];

    $h->orm->actAs('acme', function () use ($h, &$ran): void {
        $h->orm->transaction(function () use ($h, &$ran): void {
            $h->dispatcher->dispatch(new RecordContext(note: 'sync'));
            $ran['dispatched'] = \count($h->journal()->entries);
        });
        $ran['committed'] = \count($h->journal()->entries);
    });

    expect($ran)->toBe(['dispatched' => 0, 'committed' => 1])
        ->and($h->journal()->entries[0]['tenant'])->toBe('acme');
});

// The request a sync job runs inside goes on after it. Run through
// Worker::process(), as SyncQueue once took, the reset after the job ended
// that request where it stood: the rest of it ran as no tenant and nobody.
it('runs a sync job inside the request that dispatched it, and leaves the request as it was', function () {
    $h = syncHarness();
    $h->users->epochs['u1'] = 'e1';

    $after = $h->orm->actAs('acme', fn (): array => $h->auth->actingAs(new User('u1'), function () use ($h): array {
        $h->dispatcher->dispatch(new RecordContext(note: 'sync'));

        return [$h->orm->tenant(), $h->auth->id()];
    }, 'e1'));

    expect($h->journal()->entries[0]['tenant'])->toBe('acme')
        ->and($after)->toBe(['acme', 'u1']);
});

// ADR 0047: a job is never granted the subtree, whoever dispatched it. A
// sync job runs inside the request that dispatched it, and inherited the
// grant that request's panel made -- withinSubtree() worked in it.
it('runs a sync job without the subtree its dispatching request was granted', function () {
    $h = syncHarness();

    $kept = $h->orm->actAs('acme', fn (): bool => $h->orm->grantSubtree(function () use ($h): bool {
        $h->dispatcher->dispatch(new RecordContext(note: 'sync'));

        return $h->orm->isSubtreeGranted();
    }));

    expect($h->journal()->entries[0]['subtree'])->toBeFalse()
        ->and($h->journal()->entries[0]['tenant'])->toBe('acme')
        ->and($kept)->toBeTrue('the request keeps its own grant once the job is done');
});

// Nor the membership check that let the request into its panel: the query
// cache reads a binding's `subtree` to key its entries, and a request's
// binding left in force keyed a narrowed job's reads as the subtree's.
it('runs a sync job without the panel grant of the request it runs inside', function () {
    $h = syncHarness();
    $binding = new PanelBinding('admin', subtree: true);

    $h->orm->actAs('acme', fn () => $h->panels->within('admin', fn () => $h->panels->grantedWithin(
        $binding,
        fn () => $h->orm->grantSubtree(fn () => $h->dispatcher->dispatch(new RecordContext(note: 'sync'))),
    )));

    expect($h->journal()->entries[0]['panel'])->toBe('admin')
        ->and($h->journal()->entries[0]['granted'])->toBeNull()
        ->and($h->journal()->entries[0]['subtree'])->toBeFalse();
});

it('runs a sync job without the subtree from inside a fiber of a granted request', function () {
    $h = syncHarness();

    $h->orm->actAs('acme', fn () => $h->orm->grantSubtree(function () use ($h): void {
        $fiber = new \Fiber(fn () => $h->dispatcher->dispatch(new RecordContext(note: 'sync, in a fiber')));
        $fiber->start();
    }));

    expect($h->journal()->entries[0]['subtree'])->toBeFalse();
});

it('writes a database queue\'s job inside the transaction around it, so it rolls back with it', function () {
    $path = sys_get_temp_dir() . '/tinvar-jobs-outbox-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $connection = PdoConnection::sqlite($path, 'outbox', create: true);
        $queue = databaseQueue($connection);
        $h = new Harness($queue, new TenantFixture());
        $dispatcher = new Dispatcher($queue, $orm = new Orm($connection, new IdentityMap('outbox'), tenants: Tenancy::flat()), $h->signer, $h->auth);

        expect($queue->enlistsIn($connection))->toBeTrue();

        $orm->transaction(function () use ($dispatcher, $path): void {
            $dispatcher->dispatch(new RecordContext(note: 'kept'));
            // A worker's connection cannot see it before the commit.
            $other = PdoConnection::sqlite($path, 'worker');
            expect($other->select('SELECT COUNT(*) AS n FROM jobs')[0]['n'])->toBe(0);
        });
        try {
            $orm->transaction(function () use ($dispatcher): void {
                $dispatcher->dispatch(new RecordContext(note: 'lost'));

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        expect(PdoConnection::sqlite($path, 'worker')->select('SELECT COUNT(*) AS n FROM jobs')[0]['n'])->toBe(1);
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
});

/** Runs $work with SIGALRM going to the worker, as `queue:work` arranges, and puts it back after. */
function withAlarm(Harness $h, \Closure $work): void
{
    if (!\function_exists('pcntl_signal')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    $async = pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static fn () => $h->worker->onAlarm());
    try {
        $work();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($async);
    }
}

it('stops a job at its timeout and counts the try as failed', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new Sleeps());

    withAlarm($h, function () use ($h): void {
        $started = microtime(true);
        $h->work();
        expect(microtime(true) - $started)->toBeLessThan(2.5);
    });

    expect($h->journal()->entries)->toBe([])
        ->and(arrayQueue($h)->failed()[0]->error)->toContain('ran past its 1s timeout');
});

it('fails a job that swallows its timeout, and gives up the process to one that will not stop', function () {
    $fixture = new TenantFixture();
    $h = new Harness(null, $fixture);
    $h->worker = new \Tinvar\Jobs\Worker(
        $h->queue,
        $h->runner,
        $h->scope,
        clock: fn (): int => $h->now,
        exit: function (int $status) use ($h): void {
            $h->exits[] = $status;
        },
        graceSeconds: 1,
    );
    $h->dispatcher->dispatch(new Sleeps(swallow: true));

    withAlarm($h, fn () => $h->work());

    // Swallowed at one second, still running at two: the worker would have
    // exited for a fresh one (here it is told not to), and the try that
    // returned at three is a failed one all the same.
    expect($h->journal()->entries)->toBe([['swallowed' => true], ['finished' => true]])
        ->and($h->exits)->toBe([\Tinvar\Jobs\Worker::EXIT_TIMED_OUT])
        ->and(arrayQueue($h)->failed()[0]->error)->toContain('ran past its 1s timeout');
});

it('leaves nothing written down as held when it exits for a job that would not stop at its timeout', function () {
    $h = new Harness();
    $directory = sys_get_temp_dir() . '/tinvar-held-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $held = "{$directory}/queue-work.held";
    $heldAtExit = [];
    $h->worker = new \Tinvar\Jobs\Worker(
        $h->queue,
        $h->runner,
        $h->scope,
        clock: fn (): int => $h->now,
        exit: function (int $status) use ($h, $held, &$heldAtExit): void {
            $h->exits[] = $status;
            $heldAtExit[] = is_file($held);
        },
        graceSeconds: 1,
        held: $held,
    );
    $h->dispatcher->dispatch(new Sleeps(swallow: true));

    withAlarm($h, fn () => $h->work());

    // The process would have ended there: a file left naming it would be
    // read at serve's next stop, and the timed-out job handed back with its
    // try given back.
    expect($h->exits)->toBe([\Tinvar\Jobs\Worker::EXIT_TIMED_OUT])
        ->and($heldAtExit)->toBe([false]);
    @rmdir($directory);
});

it('stops when asked, after the job in hand', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new RecordContext(note: 'one'));
    $h->dispatcher->dispatch(new RecordContext(note: 'two'));
    // Asked on the line the first job is logged with. Not on any line: a
    // worker starting past its memory limit -- inside a test process that
    // has grown, say -- says so before it takes a job.
    $worker = new \Tinvar\Jobs\Worker($h->queue, $h->runner, $h->scope, log: function (string $line) use (&$worker): void {
        if (str_contains($line, '(attempt ')) {
            $worker?->stop();
        }
    }, clock: fn (): int => $h->now);

    expect($worker->run(new \Tinvar\Jobs\WorkerOptions(signals: false)))->toBe(1)
        ->and($h->queue->size('default'))->toBe(1);
});
