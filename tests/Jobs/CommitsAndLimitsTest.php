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
use Tinvar\Queue\SyncQueue;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Jobs\Fixtures\Sleeps;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;

require_once __DIR__ . '/Fixtures/jobs.php';

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

it('runs a sync job after the commit it waited for, through the same checks a worker makes', function () {
    $order = [];
    $h = null;
    $queue = new SyncQueue(static function ($job) use (&$h, &$order): void {
        \assert($h instanceof Harness);
        $order[] = 'ran';
        $h->runner->run($h->runner->open($job->payload));
    });
    $h = new Harness($queue);

    $h->orm->actAs('acme', function () use ($h, &$order): void {
        $h->orm->transaction(function () use ($h, &$order): void {
            $h->dispatcher->dispatch(new RecordContext(note: 'sync'));
            $order[] = 'dispatched';
        });
    });
    $order[] = 'committed';

    expect($order)->toBe(['dispatched', 'ran', 'committed'])
        ->and($h->journal()->entries[0]['tenant'])->toBe('acme');
});

it('writes a database queue\'s job inside the transaction around it, so it rolls back with it', function () {
    $path = sys_get_temp_dir() . '/tinvar-jobs-outbox-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $connection = PdoConnection::sqlite($path, 'outbox', create: true);
        $queue = databaseQueue($connection);
        $h = new Harness($queue, new TenantFixture());
        $dispatcher = new Dispatcher($queue, $orm = new Orm($connection, new IdentityMap('outbox')), $h->signer, $h->auth);

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
    $h->worker = new \Tinvar\Queue\Worker(
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
        ->and($h->exits)->toBe([\Tinvar\Queue\Worker::EXIT_TIMED_OUT])
        ->and(arrayQueue($h)->failed()[0]->error)->toContain('ran past its 1s timeout');
});

it('stops when asked, after the job in hand', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new RecordContext(note: 'one'));
    $h->dispatcher->dispatch(new RecordContext(note: 'two'));
    $worker = new \Tinvar\Queue\Worker($h->queue, $h->runner, $h->scope, log: function (string $line) use (&$worker): void {
        $worker?->stop();
    }, clock: fn (): int => $h->now);

    expect($worker->run(new \Tinvar\Queue\WorkerOptions(signals: false)))->toBe(1)
        ->and($h->queue->size('default'))->toBe(1);
});
