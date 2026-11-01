<?php

declare(strict_types=1);

/**
 * Unique jobs, chains, batches and rate-limited queues (ADR 0100), on a
 * queue in memory and on SQLite: each job of a chain or batch sealed with
 * its dispatcher's tenant, user and epoch and checked again on its own,
 * every lock lapsing, and every count moving in the transaction that ends
 * the job it counts.
 */

namespace Tinvar\Tests\Jobs\Coordination;

use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Queue;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Jobs\Batch;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\Rate;
use Tinvar\Jobs\SyncQueue;
use Tinvar\Jobs\UniqueJob;
use Tinvar\Jobs\Worker;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Orm\Orm;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\DatabaseQueue;
use Tinvar\Queue\RedisQueue;
use Tinvar\Tests\Support\Fixtures\Databases;
use Tinvar\Jobs\Chain;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\JobControl;
use Tinvar\Queue\Queue as JobQueue;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Jobs\Fixtures\behavesAsAQueue;
use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;
use function Tinvar\Tests\Jobs\Fixtures\schemaOf;

require_once __DIR__ . '/Fixtures/jobs.php';
require_once __DIR__ . '/Fixtures/contract.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** Notes that it ran, as whom and where. */
final readonly class Step implements Job
{
    public function __construct(public string $note) {}

    public function handle(Journal $journal, Orm $orm, AuthContext $auth, PanelContext $panels): void
    {
        $journal->entries[] = ['step' => $this->note, 'tenant' => $orm->tenant(), 'user' => $auth->id(), 'panel' => $panels->currentOrNull()];
    }
}

/** Fails, once and for good. */
#[Queue(tries: 1)]
final readonly class Breaks implements Job
{
    public function __construct(public string $note = 'breaks') {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['step' => $this->note];

        throw new \RuntimeException('breaks on purpose');
    }
}

/** Runs as its dispatcher. */
#[Queue(asUser: true)]
final readonly class StepAsUser implements Job
{
    public function __construct(public string $note) {}

    public function handle(Journal $journal, AuthContext $auth): void
    {
        $journal->entries[] = ['step' => $this->note, 'user' => $auth->id()];
    }
}

#[Panel('admin')]
final readonly class AdminStep implements Job
{
    public function handle(Journal $journal, PanelContext $panels): void
    {
        $journal->entries[] = ['step' => 'admin', 'panel' => $panels->current()];
    }
}

/** Unique for ten minutes per account. */
#[Queue(unique: 600)]
final readonly class SyncAccount implements Job, UniqueJob
{
    public function __construct(public string $account) {}

    public function uniqueId(): string
    {
        return $this->account;
    }

    public function handle(Journal $journal, Orm $orm): void
    {
        $journal->entries[] = ['synced' => $this->account, 'tenant' => $orm->tenant()];
    }
}

/** Unique, and failing for good. */
#[Queue(unique: 600, tries: 1)]
final readonly class UniqueBreaks implements Job
{
    public function handle(): void
    {
        throw new \RuntimeException('unique and broken');
    }
}

/** Unique by its arguments, as a unique job is unless it says otherwise (ADR 0118). */
#[Queue(unique: 600)]
final readonly class Reindex implements Job
{
    public function __construct(public string $account) {}

    public function handle(Journal $journal): void
    {
        $journal->entries[] = ['reindexed' => $this->account];
    }
}

/** A job of a batch that adds another to it, proven a member by its run's control. */
final readonly class Grows implements Job
{
    public function handle(Journal $journal, Dispatcher $jobs, JobControl $job): void
    {
        $journal->entries[] = ['step' => 'grows', 'added' => $jobs->addToBatch($job, new Step('grown'))];
    }
}

/** Unique per user, running as them. */
#[Queue(unique: 600, asUser: true)]
final readonly class UniquePerUser implements Job
{
    public function handle(): void {}
}

function queues(): array
{
    return [
        'in memory' => [static fn (TenantFixture $f): JobQueue => new ArrayQueue()],
        'SQLite' => [static function (TenantFixture $f): JobQueue {
            $queue = databaseQueue($f->connection);
            DatabaseQueue::defineCoordination(schemaOf($f->connection));
            DatabaseQueue::defineSlidingRates(schemaOf($f->connection));

            return $queue;
        }],
        // Where a Postgres server runs, as CI's does: the queue's locks,
        // windows and batches in a schema of their own, dropped when the
        // run ends, so each tenant's are held apart in Postgres's own SQL.
        ...(Databases::postgresMissing() === null ? ['Postgres' => [static function (TenantFixture $f): JobQueue {
            $pdo = Databases::another('pgsql');
            $schema = 'tinvar_coordination_' . bin2hex(random_bytes(6));
            $pdo->exec("CREATE SCHEMA {$schema}");
            $pdo->exec("SET search_path TO {$schema}");
            register_shutdown_function(static fn () => $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE"));
            $connection = new \Tinvar\Orm\PdoConnection($pdo, 'pgsql');
            $queue = databaseQueue($connection);
            DatabaseQueue::defineCoordination(schemaOf($connection));
            DatabaseQueue::defineSlidingRates(schemaOf($connection));

            return $queue;
        }]] : []),
        // Where a Redis server runs, as CI's does (ADR 0118).
        ...(Databases::redisMissing() === null ? ['Redis' => [static fn (TenantFixture $f): JobQueue => new RedisQueue(
            Databases::redisClient(),
            '{tinvar-test-' . bin2hex(random_bytes(4)) . '}:queue:',
        )]] : []),
    ];
}

function harness(\Closure $make): Harness
{
    $fixture = new TenantFixture();

    return new Harness($make($fixture), $fixture);
}

/** @return list<string|null> each entry's step, in order */
function steps(Harness $h): array
{
    return array_map(static fn (array $entry): mixed => $entry['step'] ?? null, $h->journal()->entries);
}

function coordination(Harness $h): Coordination
{
    \assert($h->queue instanceof Coordination);

    return $h->queue;
}

describe('a unique job', function () {
    it('is queued once while one is waiting, and dispatch answers with the one there', function (\Closure $make) {
        $h = harness($make);
        $first = $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new SyncAccount('a-1')));
        $again = $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new SyncAccount('a-1')));
        $other = $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new SyncAccount('a-2')));

        expect($again)->toBe($first)
            ->and($other)->not->toBe($first)
            ->and($h->queue->size('default'))->toBe(2);

        // Done, its lock is given back: the next is queued.
        $h->work();
        $next = $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new SyncAccount('a-1')));
        expect($next)->not->toBe($first)
            ->and($h->queue->size('default'))->toBe(1);
    })->with(queues());

    it('holds no lock across tenants, nor across users where it runs as one', function (\Closure $make) {
        $h = harness($make);
        $acme = $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new SyncAccount('a-1')));
        $globex = $h->orm->actAs('globex', fn () => $h->dispatcher->dispatch(new SyncAccount('a-1')));
        expect($globex)->not->toBe($acme);

        $h->users->epochs = ['u-1' => 'e', 'u-2' => 'e'];
        $one = $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->dispatch(new UniquePerUser()), 'e');
        $two = $h->auth->actingAs(new User('u-2'), fn () => $h->dispatcher->dispatch(new UniquePerUser()), 'e');
        $oneAgain = $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->dispatch(new UniquePerUser()), 'e');
        expect($two)->not->toBe($one)
            ->and($oneAgain)->toBe($one)
            ->and($h->queue->size('default'))->toBe(4);
    })->with(queues());

    it('lapses, so a job that never ends holds nothing for ever', function (\Closure $make) {
        $h = harness($make);
        $first = $h->dispatcher->dispatch(new SyncAccount('a-1'));
        $h->now += 599;
        expect($h->dispatcher->dispatch(new SyncAccount('a-1')))->toBe($first);
        $h->now += 1;
        expect($h->dispatcher->dispatch(new SyncAccount('a-1')))->not->toBe($first)
            ->and($h->queue->size('default'))->toBe(2);
    })->with(queues());

    it('gives its lock back when it fails for good', function (\Closure $make) {
        $h = harness($make);
        $first = $h->dispatcher->dispatch(new UniqueBreaks());
        $h->work();

        expect($h->queue->failed())->toHaveCount(1)
            ->and($h->dispatcher->dispatch(new UniqueBreaks()))->not->toBe($first);
    })->with(queues());

    // Review: refusing a copy settled it as the original -- its lock given
    // back, its chain's catch queued, its batch cancelled.
    it('keeps its lock when a copy of its payload, under another id, is refused', function (\Closure $make) {
        $h = harness($make);
        $first = $h->dispatcher->dispatch(new SyncAccount('a-1'));
        $payload = (string) $h->queue->reserve(['default'], $h->now, 60)?->payload;
        $h->queue->push(new \Tinvar\Queue\PendingJob('copied-' . $first, 'copies', $payload, $h->now));
        $copy = $h->queue->reserve(['copies'], $h->now, 60);
        \assert($copy !== null);

        expect($h->worker->process($copy))->toBe('failed')
            ->and($h->dispatcher->dispatch(new SyncAccount('a-1')))->toBe($first, 'the original still holds its lock');
    })->with(queues());

    it('keeps no word of what it is about: its key is a digest', function () {
        $fixture = new TenantFixture();
        $queue = databaseQueue($fixture->connection);
        DatabaseQueue::defineCoordination(schemaOf($fixture->connection));
        $h = new Harness($queue, $fixture);
        $h->dispatcher->dispatch(new SyncAccount('account-secret-name'));

        $row = $fixture->connection->select('SELECT lock_key FROM job_locks')[0];
        expect($row['lock_key'])->toMatch('/^[0-9a-f]{64}$/')
            ->and(json_encode($fixture->connection->select('SELECT * FROM job_locks')))->not->toContain('account-secret-name');
    });

    it('takes its lock in the transaction it is dispatched in, and loses it when that rolls back', function () {
        $fixture = new TenantFixture();
        $queue = databaseQueue($fixture->connection);
        DatabaseQueue::defineCoordination(schemaOf($fixture->connection));
        $h = new Harness($queue, $fixture);

        try {
            $h->orm->transaction(function () use ($h): void {
                $h->dispatcher->dispatch(new SyncAccount('a-1'));

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        expect($fixture->connection->select('SELECT COUNT(*) AS n FROM job_locks')[0]['n'])->toBe(0)
            ->and($queue->size('default'))->toBe(0);
        $h->dispatcher->dispatch(new SyncAccount('a-1'));
        expect($queue->size('default'))->toBe(1);
    });

    it('is refused in a chain or a batch', function () {
        $h = new Harness();
        expect(fn () => $h->dispatcher->chain([new Step('a'), new SyncAccount('a-1')]))->toThrow(\LogicException::class, 'unique')
            ->and(fn () => $h->dispatcher->batch(Batch::of(new SyncAccount('a-1'))))->toThrow(\LogicException::class, 'unique');
    });

    it('refuses a lock that would outlast a week', function () {
        expect(fn () => new Queue(unique: Queue::MAX_UNIQUE + 1))->toThrow(\InvalidArgumentException::class);
    });
});

describe('a chain', function () {
    it('runs its jobs one after another, each queued once the one before is done', function (\Closure $make) {
        $h = harness($make);
        $h->users->epochs = ['u-1' => 'e1'];
        $h->orm->actAs('acme', fn () => $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->chain([new Step('a'), new StepAsUser('b'), new Step('c')]), 'e1'));

        expect($h->queue->size('default'))->toBe(1);
        $h->work();

        expect(steps($h))->toBe(['a', 'b', 'c'])
            // One tenant for all; the user only for the job that asks for one.
            ->and(array_column($h->journal()->entries, 'tenant'))->toBe(['acme', 'acme'])
            ->and(array_column($h->journal()->entries, 'user'))->toBe([null, 'u-1', null])
            ->and($h->queue->size('default'))->toBe(0);
    })->with(queues());

    it('ends at a job that fails for good', function (\Closure $make) {
        $h = harness($make);
        $h->dispatcher->chain([new Step('a'), new Breaks('b'), new Step('c')]);
        $h->work();

        expect(steps($h))->toBe(['a', 'b'])
            ->and($h->queue->failed())->toHaveCount(1)
            ->and($h->queue->size('default'))->toBe(0);
    })->with(queues());

    it('checks each job again on its own: one whose user moved epoch is refused, and ends it', function (\Closure $make) {
        $h = harness($make);
        $h->users->epochs = ['u-1' => 'e1'];
        $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->chain([new Step('a'), new StepAsUser('b'), new Step('c')]), 'e1');
        $h->worker->run(new WorkerOptions(maxJobs: 1, signals: false));
        expect(steps($h))->toBe(['a']);

        // Signed out everywhere between the two.
        $h->users->epochs = ['u-1' => 'e2'];
        $h->work();

        expect(steps($h))->toBe(['a'])
            ->and($h->queue->failed()[0]->error ?? '')->toContain('epoch');
    })->with(queues());

    it('runs each job in the panel it was sealed for', function (\Closure $make) {
        $h = harness($make);
        $h->dispatcher->chain([new Step('a'), new AdminStep()]);
        $h->work();

        expect($h->journal()->entries[1])->toBe(['step' => 'admin', 'panel' => 'admin']);
    })->with(queues());

    it('refuses a chain whose payload was edited to add a job', function () {
        $h = new Harness();
        $h->dispatcher->chain([new Step('a'), new Step('b')]);
        $queue = $h->queue;
        \assert($queue instanceof ArrayQueue);
        $job = $queue->reserve(['default'], $h->now, 60);
        \assert($job !== null);
        $tampered = str_replace('"chain":[', '"chain":[{"job":"' . addslashes(Breaks::class) . '","args":[],"by":null,"panel":null},', $job->payload);
        $queue->requeue($job, $h->now, $tampered);
        $h->work();

        expect(steps($h))->toBe([])
            ->and($h->queue->failed()[0]->error ?? '')->toContain('does not verify');
    });
});

describe('a batch', function () {
    it('runs its jobs, then its then and finally once, and reports how far it got', function (\Closure $make) {
        $h = harness($make);
        $id = $h->orm->actAs('acme', fn () => $h->dispatcher->batch(
            Batch::of(new Step('a'), new Step('b'), new Step('c'))->then(new Step('then'))->catch(new Step('catch'))->finally(new Step('finally')),
        ));

        $progress = $h->orm->actAs('acme', fn () => $h->dispatcher->batchProgress($id));
        expect($progress?->total)->toBe(3)
            ->and($progress?->pending)->toBe(3)
            ->and($h->queue->size('default'))->toBe(3);

        $h->work();
        $progress = $h->orm->actAs('acme', fn () => $h->dispatcher->batchProgress($id));
        expect(steps($h))->toBe(['a', 'b', 'c', 'then', 'finally'])
            ->and(array_unique(array_column($h->journal()->entries, 'tenant')))->toBe(['acme'])
            ->and($progress?->finished)->toBeTrue()
            ->and($progress?->ended())->toBe(3)
            ->and($progress?->failed)->toBe(0);
    })->with(queues());

    it('runs its catch at the first failure, skips the jobs still waiting, and runs finally', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(
            Batch::of(new Step('a'), new Breaks('b'), new Step('c'), new Step('d'))->then(new Step('then'))->catch(new Step('catch'))->finally(new Step('finally')),
        );
        $h->work();

        $progress = $h->dispatcher->batchProgress($id);
        expect(steps($h))->toBe(['a', 'b', 'catch', 'finally'])
            ->and($progress?->cancelled)->toBeTrue()
            ->and($progress?->failed)->toBe(1)
            ->and($progress?->pending)->toBe(0)
            ->and(implode("\n", $h->log))->toContain('skipped');
    })->with(queues());

    it('runs on past a failure it allows, with catch once and no then', function (\Closure $make) {
        $h = harness($make);
        $h->dispatcher->batch(
            Batch::of(new Breaks('b1'), new Step('a'), new Breaks('b2'))->allowFailures()->then(new Step('then'))->catch(new Step('catch'))->finally(new Step('finally')),
        );
        $h->work();

        expect(steps($h))->toBe(['b1', 'a', 'b2', 'catch', 'finally']);
    })->with(queues());

    it('counts a job once, however often it runs: a retried failure finishes nothing again', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(Batch::of(new Breaks('b'), new Step('a'))->allowFailures()->finally(new Step('finally')));
        $h->work();
        expect(steps($h))->toBe(['b', 'a', 'finally']);

        $failed = $h->queue->failed();
        expect($failed)->toHaveCount(1);
        $h->queue->retry($failed[0]->id, $h->now);
        $h->work();

        $progress = $h->dispatcher->batchProgress($id);
        expect(steps($h))->toBe(['b', 'a', 'finally', 'b'])
            ->and($progress?->failed)->toBe(1)
            ->and($progress?->pending)->toBe(0);
    })->with(queues());

    it('is read back only where it was dispatched: its tenant, its panel, its user', function (\Closure $make) {
        $h = harness($make);
        $h->users->epochs = ['u-1' => 'e1'];
        $id = $h->orm->actAs('acme', fn () => $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->batch(Batch::of(new Step('a'))), 'e1'));

        expect($h->orm->actAs('acme', fn () => $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->batchProgress($id), 'e1')))->not->toBeNull()
            ->and($h->orm->actAs('globex', fn () => $h->auth->actingAs(new User('u-1'), fn () => $h->dispatcher->batchProgress($id), 'e1')))->toBeNull()
            ->and($h->orm->actAs('acme', fn () => $h->auth->actingAs(new User('u-2'), fn () => $h->dispatcher->batchProgress($id), 'e1')))->toBeNull()
            ->and($h->orm->actAs('acme', fn () => $h->dispatcher->batchProgress($id)))->toBeNull()
            ->and($h->orm->actAs('acme', fn () => $h->auth->actingAs(new User('u-1'), fn () => $h->panels->within('admin', fn () => $h->dispatcher->batchProgress($id)), 'e1')))->toBeNull();
    })->with(queues());

    it('refuses a job whose batch the queue does not have', function () {
        $h = new Harness();
        $h->dispatcher->batch(Batch::of(new Step('a')));
        $queue = $h->queue;
        \assert($queue instanceof ArrayQueue);

        // Its payload, moved to a queue that never heard of its batch.
        $other = new Harness();
        $job = $queue->pushed()[0];
        $other->queue->push($job);
        $other->work();
        expect($other->queue->failed()[0]->error ?? '')->toContain('batch');
    });
});

describe('a rate-limited queue', function () {
    it('starts no more jobs in a window than its rate, across workers, and the rest in the next', function (\Closure $make) {
        $h = harness($make);
        for ($i = 0; $i < 5; $i++) {
            $h->dispatcher->dispatch(new Step("s{$i}"));
        }
        $options = new WorkerOptions(stopWhenEmpty: true, signals: false, rates: ['default' => new Rate(2, 60)]);
        $h->now = 1_800_000_040;

        expect($h->worker->run($options))->toBe(2);
        // A second worker on the same store finds the window spent too.
        $second = new Worker($h->queue, $h->runner, $h->scope, clock: fn (): int => $h->now);
        expect($second->run($options))->toBe(0);

        $h->now += 20;
        expect($h->worker->run($options))->toBe(2);
        $h->now += 60;
        expect($h->worker->run($options))->toBe(1)
            ->and(steps($h))->toBe(['s0', 's1', 's2', 's3', 's4']);
    })->with(queues());

    it('spends nothing of its window when it has nothing waiting, and holds no other queue back', function (\Closure $make) {
        $h = harness($make);
        $options = new WorkerOptions(queues: ['default', 'other'], stopWhenEmpty: true, signals: false, rates: ['default' => new Rate(1, 60)]);
        expect($h->worker->run($options))->toBe(0);

        $h->dispatcher->dispatch(new Step('limited'));
        $h->dispatcher->dispatch(new Step('second'));
        expect($h->worker->run($options))->toBe(1)
            ->and(steps($h))->toBe(['limited']);
    })->with(queues());

    it('is at least one start in a window of a second to a day', function () {
        expect(fn () => new Rate(0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Rate(1, Rate::MAX_PER + 1))->toThrow(\InvalidArgumentException::class);
    });
});

describe('on the sync queue', function () {
    it('runs a chain and a batch here and now, in order', function () {
        $h = new Harness();
        $sync = new SyncQueue($h->runner);
        $dispatcher = new \Tinvar\Jobs\Dispatcher($sync, $h->orm, $h->signer, $h->auth, $h->panels, $h->registry, fn (): int => $h->now);

        $dispatcher->chain([new Step('a'), new Step('b')]);
        $dispatcher->batch(Batch::of(new Step('c'), new Step('d'))->then(new Step('then'))->finally(new Step('finally')));
        $dispatcher->dispatch(new SyncAccount('x'));
        $dispatcher->dispatch(new SyncAccount('x'));

        expect(steps($h))->toBe(['a', 'b', 'c', 'd', 'then', 'finally', null, null]);
    });
});

it('refuses chains, batches and unique jobs on a queue that keeps none of them', function () {
    $h = new Harness();
    $plain = new class implements JobQueue {
        public function push(\Tinvar\Queue\PendingJob $job): void {}

        public function reserve(array $queues, int $now, int $reserveFor): ?\Tinvar\Queue\ReservedJob
        {
            return null;
        }

        public function extend(\Tinvar\Queue\ReservedJob $job, int $until): bool
        {
            return false;
        }

        public function delete(\Tinvar\Queue\ReservedJob $job): bool
        {
            return false;
        }

        public function release(\Tinvar\Queue\ReservedJob $job, int $availableAt): bool
        {
            return false;
        }

        public function requeue(\Tinvar\Queue\ReservedJob $job, int $availableAt, ?string $payload = null): bool
        {
            return false;
        }

        public function fail(\Tinvar\Queue\ReservedJob $job, string $error, int $failedAt): bool
        {
            return false;
        }

        public function enlistsIn(\Tinvar\Orm\Connection $connection): bool
        {
            return false;
        }

        public function size(string $queue): int
        {
            return 0;
        }

        public function failed(): array
        {
            return [];
        }

        public function retry(string $id, int $now): bool
        {
            return false;
        }
    };
    $dispatcher = new \Tinvar\Jobs\Dispatcher($plain, $h->orm, $h->signer, $h->auth);

    expect(fn () => $dispatcher->chain([new Step('a')]))->toThrow(\LogicException::class, 'Coordination')
        ->and(fn () => $dispatcher->batch(Batch::of(new Step('a'))))->toThrow(\LogicException::class, 'Coordination')
        ->and(fn () => $dispatcher->dispatch(new SyncAccount('a')))->toThrow(\LogicException::class, 'Coordination')
        ->and($dispatcher->batchProgress('anything'))->toBeNull();

    $worker = new Worker($plain, $h->runner, $h->scope);
    expect(fn () => $worker->run(new WorkerOptions(stopWhenEmpty: true, signals: false, rates: ['default' => new Rate(1)])))->toThrow(\LogicException::class, 'Coordination');
});

describe('a unique job with no uniqueId() of its own', function () {
    // ADR 0118: one per class held the place of every other account's.
    it('is unique by its arguments: the same job once, another account\'s beside it', function (\Closure $make) {
        $h = harness($make);
        $first = $h->dispatcher->dispatch(new Reindex('a-1'));
        expect($h->dispatcher->dispatch(new Reindex('a-1')))->toBe($first)
            ->and($h->dispatcher->dispatch(new Reindex('a-2')))->not->toBe($first);

        $h->work();
        expect(array_column($h->journal()->entries, 'reindexed'))->toBe(['a-1', 'a-2']);
    })->with(queues());
});

describe('a chain\'s catch', function () {
    it('runs when a job of the chain fails for good, in the chain\'s tenant, and the rest never do', function (\Closure $make) {
        $h = harness($make);
        $h->orm->actAs('acme', fn () => $h->dispatcher->chain(Chain::of(new Step('a'), new Breaks('b'), new Step('c'))->catch(new Step('caught'))));
        for ($round = 0; $round < 4; $round++) {
            $h->work();
        }

        expect(steps($h))->toBe(['a', 'b', 'caught'])
            ->and($h->journal()->entries[2]['tenant'])->toBe('acme');
    })->with(queues());

    it('does not run when the chain finishes', function (\Closure $make) {
        $h = harness($make);
        $h->dispatcher->chain(Chain::of(new Step('a'), new Step('b'))->catch(new Step('caught')));
        for ($round = 0; $round < 3; $round++) {
            $h->work();
        }

        expect(steps($h))->toBe(['a', 'b']);
    })->with(queues());

    it('is refused when edited into a payload: its catch is under the MAC', function () {
        $h = new Harness();
        $h->dispatcher->chain(Chain::of(new Step('a'))->catch(new Step('caught')));
        $payload = $h->queue instanceof \Tinvar\Queue\ArrayQueue ? $h->queue->pushed()[0]->payload : '';
        $edited = str_replace('caught', 'CAUGHT', $payload);

        expect(static fn () => $h->runner->open($edited))->toThrow(\Tinvar\Jobs\JobRefused::class);
    });

    it('holds one catch a chain', function () {
        expect(static fn () => Chain::of(new Step('a'))->catch(new Step('x'))->catch(new Step('y')))->toThrow(\LogicException::class);
    });
});

describe('a chain inside a batch', function () {
    it('is counted once, at its end, and the batch\'s then waits for it', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(Batch::of(Chain::of(new Step('c1'), new Step('c2'), new Step('c3')), new Step('alone'))->then(new Step('then')));
        expect($h->dispatcher->batchProgress($id)?->total)->toBe(2);
        for ($round = 0; $round < 5; $round++) {
            $h->work();
        }

        $steps = steps($h);
        expect($steps)->toContain('c1', 'c2', 'c3', 'alone', 'then')
            ->and(array_search('then', $steps, true))->toBeGreaterThan(array_search('c3', $steps, true))
            ->and($h->dispatcher->batchProgress($id)?->finished)->toBeTrue()
            ->and($h->dispatcher->batchProgress($id)?->pending)->toBe(0);
    })->with(queues());

    it('fails the batch at a job that fails for good, and runs the chain\'s catch and the batch\'s', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(Batch::of(Chain::of(new Step('c1'), new Breaks('c2'), new Step('c3'))->catch(new Step('chain caught')))->catch(new Step('batch caught'))->finally(new Step('finally')));
        for ($round = 0; $round < 5; $round++) {
            $h->work();
        }

        expect(steps($h))->toContain('c1', 'c2', 'chain caught', 'batch caught', 'finally')
            ->and(steps($h))->not->toContain('c3')
            ->and($h->dispatcher->batchProgress($id)?->failed)->toBe(1);
    })->with(queues());

    it('stops where its batch was cancelled: the job in hand counted, the rest never queued', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(Batch::of(new Breaks('first'), Chain::of(new Step('c1'), new Step('c2'))));
        for ($round = 0; $round < 4; $round++) {
            $h->work();
        }

        expect(steps($h))->toBe(['first'])
            ->and($h->dispatcher->batchProgress($id)?->finished)->toBeTrue()
            ->and($h->queue->size('default'))->toBe(0);
    })->with(queues());
});

describe('adding to a batch after dispatch', function () {
    it('adds jobs to a batch still running, where it was dispatched, and its then waits for them', function (\Closure $make) {
        $h = harness($make);
        $id = $h->dispatcher->batch(Batch::of(new Step('one'))->then(new Step('then')));
        expect($h->dispatcher->addToBatch($id, new Step('two')))->toBeTrue()
            ->and($h->dispatcher->batchProgress($id)?->total)->toBe(2);
        for ($round = 0; $round < 3; $round++) {
            $h->work();
        }

        expect(steps($h))->toBe(['one', 'two', 'then']);
    })->with(queues());

    it('lets a job of the batch add to it, proven by its own verified run', function (\Closure $make) {
        $h = harness($make);
        $h->container->instance(Dispatcher::class, $h->dispatcher);
        $id = $h->orm->actAs('acme', fn () => $h->dispatcher->batch(Batch::of(new Grows())->then(new Step('then'))));
        for ($round = 0; $round < 3; $round++) {
            $h->work();
        }

        expect(steps($h))->toBe(['grows', 'grown', 'then'])
            ->and($h->journal()->entries[0]['added'])->toBeTrue()
            ->and($h->orm->actAs('acme', fn () => $h->dispatcher->batchProgress($id)?->total))->toBe(2);
    })->with(queues());

    it('refuses another tenant\'s or user\'s batch, and one that has finished', function (\Closure $make) {
        $h = harness($make);
        $id = $h->orm->actAs('acme', fn () => $h->dispatcher->batch(Batch::of(new Step('one'))));

        expect($h->orm->actAs('globex', fn () => $h->dispatcher->addToBatch($id, new Step('theirs'))))->toBeFalse()
            ->and($h->dispatcher->addToBatch($id, new Step('no tenant')))->toBeFalse()
            ->and($h->auth->actingAs(new User('u-2'), fn () => $h->orm->actAs('acme', fn () => $h->dispatcher->addToBatch($id, new Step('not mine')))))->toBeFalse();
        $h->work();
        expect($h->orm->actAs('acme', fn () => $h->dispatcher->addToBatch($id, new Step('late'))))->toBeFalse()
            ->and(steps($h))->toBe(['one']);
    })->with(queues());
});

describe('a rate per tenant', function () {
    it('holds each tenant to its own starts: one tenant\'s flood waits on itself, never another\'s', function (\Closure $make) {
        $h = harness($make);
        for ($i = 0; $i < 3; $i++) {
            $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Step("acme-{$i}")));
        }
        $h->orm->actAs('globex', fn () => $h->dispatcher->dispatch(new Step('globex')));
        $options = new WorkerOptions(stopWhenEmpty: true, signals: false, rates: ['default' => Rate::perTenant(1, 60)]);
        $h->now = 1_800_000_040;

        $h->worker->run($options);
        expect(steps($h))->toBe(['acme-0', 'globex'])
            ->and(array_filter($h->log, static fn (string $line): bool => str_contains($line, 'throttled')))->not->toBeEmpty();

        $h->now += 20;
        $h->worker->run($options);
        expect(steps($h))->toBe(['acme-0', 'globex', 'acme-1']);
        $h->now += 60;
        $h->worker->run($options);
        expect(steps($h))->toBe(['acme-0', 'globex', 'acme-1', 'acme-2']);
    })->with(queues());
});

describe('a sliding rate', function () {
    it('lets no more than its runs start in any window, across a window\'s edge', function (\Closure $make) {
        $h = harness($make);
        for ($i = 0; $i < 4; $i++) {
            $h->dispatcher->dispatch(new Step("s{$i}"));
        }
        $options = new WorkerOptions(stopWhenEmpty: true, signals: false, rates: ['default' => new Rate(2, 60, sliding: true)]);
        $h->now = 1_800_000_050;

        expect($h->worker->run($options))->toBe(2);
        // The clock's minute has turned; the last 60 seconds have not.
        $h->now = 1_800_000_065;
        expect($h->worker->run($options))->toBe(0);
        $h->now = 1_800_000_111;
        expect($h->worker->run($options))->toBe(2)
            ->and(steps($h))->toBe(['s0', 's1', 's2', 's3']);
    })->with(queues());
});

it('queues, reserves, releases and fails in memory, as every queue does', function () {
    $queue = new ArrayQueue();
    behavesAsAQueue($queue, $queue);
});
