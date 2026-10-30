<?php

declare(strict_types=1);

/**
 * A job longer than the stop: it pauses at a checkpoint when the worker is
 * stopping, or is handed back to its queue when it does not, and runs again
 * at once -- from where it paused, each unit of work once, its tries
 * untouched.
 *
 * Before, a job in hand got the stop's 70 s; longer, it was killed holding
 * its reservation and ran again from the start once the reservation lapsed,
 * its attempt counted.
 */

namespace Tinvar\Tests\Jobs\StopAndPause;

use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Container\Dependency;
use Tinvar\Container\Lifetime;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\WorkerStopping;
use Tinvar\Orm\Orm;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Jobs\StopBudget;
use Tinvar\Jobs\SyncQueue;
use Tinvar\Jobs\Worker;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Jobs\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;

require_once __DIR__ . '/Fixtures/jobs.php';

/** What a test does to the worker from inside a job: the stop's signal, the alarm. */
#[Dependency(Lifetime::Worker)]
final class Signals
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $stop = null;

    /** @var (\Closure(): void)|null */
    public ?\Closure $alarm = null;

    /** The item after which the stop comes, on the first run only. */
    public ?int $stopAfter = null;

    public function stop(): void
    {
        ($this->stop ?? static fn () => null)();
    }

    public function alarm(): void
    {
        ($this->alarm ?? static fn () => null)();
    }
}

/**
 * Works through items, pausing at a checkpoint once its worker is stopping.
 * Tried once: a pause that counted as a try would leave it none.
 */
#[QueueOptions(tries: 1)]
final readonly class Batch implements Job
{
    public function __construct(public int $items) {}

    public function handle(Journal $journal, Signals $signals, JobControl $job): void
    {
        $next = $job->checkpoint()['next'] ?? 0;
        \assert(\is_int($next));
        for ($item = $next; $item < $this->items; $item++) {
            $journal->entries[] = ['item' => $item, 'releases' => $job->releases(), 'key' => $job->idempotencyKey()];
            if ($signals->stopAfter === $item) {
                $signals->stopAfter = null;
                $signals->stop();
            }
            if ($job->stopping()) {
                $job->release(checkpoint: ['next' => $item + 1]);

                return;
            }
        }
        $journal->entries[] = ['finished' => true];
    }
}

/** Never looks at JobControl: the stop's alarm finds it still running. */
#[QueueOptions(tries: 1, timeout: 600)]
final readonly class Stubborn implements Job
{
    public function __construct(public bool $swallow = false) {}

    public function handle(Journal $journal, Signals $signals, Orm $orm): void
    {
        $journal->entries[] = ['started' => true];
        if ($signals->stop === null) {
            $journal->entries[] = ['finished' => true];

            return;
        }
        $signals->stop();
        $signals->stop = null;

        // Half-done work, inside a transaction the stop must not keep.
        $connection = $orm->connection();
        \assert($connection instanceof \Tinvar\Orm\PdoConnection);
        $connection->pdo()->beginTransaction();
        $connection->execute("INSERT INTO scripts VALUES ('half', 'half', 'acme')");

        try {
            $signals->alarm();
        } catch (WorkerStopping $e) {
            if (!$this->swallow) {
                throw $e;
            }
            $journal->entries[] = ['swallowed' => true];
            // Still going as the margin runs out.
            $signals->alarm();
        }
    }
}

/** Pauses every time. */
#[QueueOptions(tries: 1, releases: 2)]
final readonly class OnlyPauses implements Job
{
    public function handle(Journal $journal, JobControl $job): void
    {
        $journal->entries[] = ['paused' => $job->releases()];
        $job->release(30, ['n' => $job->releases()]);
    }

    public function failed(\Throwable $error): void
    {
        \Tinvar\Tests\Jobs\Fixtures\Hooks::$failed[] = $error->getMessage();
    }
}

/** Pauses once with a checkpoint, as the dispatcher, for ten seconds. */
#[QueueOptions(asUser: true)]
final readonly class PausesAsUser implements Job
{
    public function handle(Journal $journal, JobControl $job, \Tinvar\Auth\AuthContext $auth, Orm $orm): void
    {
        $journal->entries[] = ['tenant' => $orm->tenant(), 'user' => $auth->id(), 'checkpoint' => $job->checkpoint()];
        if ($job->checkpoint() === null) {
            $job->release(10, ['cursor' => 'acme-row-7']);
        }
    }
}

function queues(): array
{
    return [
        'in memory' => [static fn (TenantFixture $f): Queue => new ArrayQueue()],
        'SQLite' => [static fn (TenantFixture $f): Queue => databaseQueue($f->connection)],
    ];
}

/** @return array{int, string}|null the attempts and payload of the one job left on $queue */
function stored(Harness $h): ?array
{
    if ($h->queue instanceof ArrayQueue) {
        $ids = array_map(static fn (PendingJob $job): string => $job->id, $h->queue->pushed());
        foreach ($ids as $id) {
            if ($h->queue->attempts($id) !== null) {
                return [(int) $h->queue->attempts($id), (string) $h->queue->payload($id)];
            }
        }

        return null;
    }

    $row = $h->fixture->connection->select('SELECT attempts, payload, reserved_until, token FROM jobs')[0] ?? null;
    if ($row === null) {
        return null;
    }
    expect($row['reserved_until'])->toBeNull()->and($row['token'])->toBeNull();

    return [(int) $row['attempts'], (string) $row['payload']];
}

function signals(Harness $h): Signals
{
    $signals = $h->container->get(Signals::class);
    $signals->stop = fn () => $h->worker->onStop();
    $signals->alarm = fn () => $h->worker->onAlarm();

    return $signals;
}

function work(Harness $h, int $stopBudget = 20): int
{
    return $h->worker->run(new WorkerOptions(stopWhenEmpty: true, signals: false, stopBudget: $stopBudget));
}

it('pauses a job at its checkpoint when the worker stops, and resumes it there: each item once', function (\Closure $make) {
    $fixture = new TenantFixture();
    $h = new Harness($make($fixture), $fixture);
    signals($h)->stopAfter = 3;
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Batch(8)));

    // The first worker is stopped after item 3, and stops once it has paused.
    expect(work($h))->toBe(1);
    [$attempts, $payload] = stored($h) ?? [null, ''];
    $envelope = Envelope::open($payload, $h->signer);

    expect($attempts)->toBe(0)
        ->and($envelope->checkpoint)->toBe(['next' => 4])
        ->and($envelope->releases)->toBe(1)
        ->and($envelope->tenant)->toBe('acme')
        ->and(implode("\n", $h->log))->toContain('paused');

    // The next worker picks it up there, and finishes it.
    expect(work($h))->toBe(1)
        ->and(stored($h))->toBeNull();

    $entries = $h->journal()->entries;
    $items = array_column(array_filter($entries, static fn (array $e): bool => isset($e['item'])), 'item');
    $keys = array_unique(array_column(array_filter($entries, static fn (array $e): bool => isset($e['key'])), 'key'));
    expect($items)->toBe(range(0, 7))
        ->and(end($entries))->toBe(['finished' => true])
        ->and(array_column(array_filter($entries, static fn (array $e): bool => isset($e['item'])), 'releases'))->toBe([0, 0, 0, 0, 1, 1, 1, 1])
        // One idempotency key across the pause: the same job.
        ->and($keys)->toHaveCount(1)
        ->and($h->queue->failed())->toBe([]);
})->with(queues());

it('pauses a job as often as the stop comes without spending its one try', function () {
    $h = new Harness();
    $signals = signals($h);
    $h->dispatcher->dispatch(new Batch(6));

    foreach ([0, 2, 4] as $after) {
        $signals->stopAfter = $after;
        work($h);
    }

    $items = array_column(array_filter($h->journal()->entries, static fn (array $e): bool => isset($e['item'])), 'item');
    expect($items)->toBe([0, 1, 2, 3, 4])
        ->and(stored($h)[0] ?? null)->toBe(0);

    work($h);
    $items = array_column(array_filter($h->journal()->entries, static fn (array $e): bool => isset($e['item'])), 'item');
    expect($items)->toBe([0, 1, 2, 3, 4, 5])
        ->and($h->queue->failed())->toBe([])
        ->and($h->queue->size('default'))->toBe(0);
});

it('hands back a job that does not pause when the stop budget runs out, and runs it again once', function (\Closure $make) {
    $fixture = new TenantFixture();
    $h = new Harness($make($fixture), $fixture);
    signals($h);
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Stubborn()));

    work($h);

    // Back on the queue at once, its one try given back, and what it had
    // half done rolled back.
    [$attempts] = stored($h) ?? [null];
    expect($attempts)->toBe(0)
        ->and(implode("\n", $h->log))->toContain('handed back')
        ->and($h->fixture->connection->select("SELECT COUNT(*) AS n FROM scripts WHERE id = 'half'")[0]['n'])->toBe(0)
        ->and($h->queue->failed())->toBe([]);

    work($h);

    expect($h->journal()->entries)->toBe([['started' => true], ['started' => true], ['finished' => true]])
        ->and($h->queue->size('default'))->toBe(0)
        ->and($h->queue->failed())->toBe([]);
})->with(queues());

it('hands back a job that swallows the stop from the alarm, rolls back its transaction, and exits', function (\Closure $make) {
    $fixture = new TenantFixture();
    $h = new Harness($make($fixture), $fixture);
    signals($h);
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Stubborn(swallow: true)));

    work($h);

    // The worker would have exited from the alarm (here it is told not
    // to); the job was handed back before it did, and the run that went on
    // regardless recorded nothing over it.
    [$attempts] = stored($h) ?? [null];
    expect($h->exits)->toBe([0])
        ->and($attempts)->toBe(0)
        ->and(implode("\n", $h->log))->toContain('would not stop within the stop budget; handed back')
        ->and($h->fixture->connection->select("SELECT COUNT(*) AS n FROM scripts WHERE id = 'half'")[0]['n'])->toBe(0);

    work($h);

    expect($h->journal()->entries)->toBe([['started' => true], ['swallowed' => true], ['started' => true], ['finished' => true]])
        ->and($h->queue->size('default'))->toBe(0)
        ->and($h->queue->failed())->toBe([]);
})->with(queues());

it('hands back untouched a job taken as the stop came', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new Batch(2));
    $job = $h->queue->reserve(['default'], $h->now, 60);
    \assert($job !== null);

    $h->worker->onStop();

    expect($h->worker->process($job))->toBe('handed back')
        ->and($h->journal()->entries)->toBe([])
        ->and(stored($h)[0] ?? null)->toBe(0);
});

it('fails a job that pauses more often than it may, and runs its failed() hook', function () {
    \Tinvar\Tests\Jobs\Fixtures\Hooks::$failed = [];
    $h = new Harness();
    $h->dispatcher->dispatch(new OnlyPauses());

    for ($run = 0; $run < 3; $run++) {
        work($h);
        $h->now += 30;
    }

    expect(array_column($h->journal()->entries, 'paused'))->toBe([0, 1, 2])
        ->and($h->queue->failed())->toHaveCount(1)
        ->and($h->queue->failed()[0]->error)->toContain('#[Queue(releases: 2)]')
        ->and(\Tinvar\Tests\Jobs\Fixtures\Hooks::$failed)->toHaveCount(1);
});

it('holds a paused job to its delay', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new OnlyPauses());

    work($h);
    expect(work($h))->toBe(0);
    $h->now += 30;
    expect(work($h))->toBe(1);
});

it('keeps a paused job in the tenant, as the user, on the epoch it was dispatched with', function () {
    $h = new Harness();
    $h->users->epochs['u1'] = 'epoch-1';
    $h->auth->actingAs(new User('u1'), fn () => $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new PausesAsUser())), 'epoch-1');

    work($h);
    $envelope = Envelope::open(stored($h)[1] ?? '', $h->signer);
    expect([$envelope->tenant, $envelope->user, $envelope->epoch, $envelope->checkpoint])
        ->toBe(['acme', 'u1', 'epoch-1', ['cursor' => 'acme-row-7']]);

    $h->now += 10;
    work($h);
    expect($h->journal()->entries)->toBe([
        ['tenant' => 'acme', 'user' => 'u1', 'checkpoint' => null],
        ['tenant' => 'acme', 'user' => 'u1', 'checkpoint' => ['cursor' => 'acme-row-7']],
    ]);
});

it('refuses a checkpoint edited on the queue, or moved to another tenant', function (string $from, string $to) {
    $h = new Harness();
    signals($h)->stopAfter = 0;
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Batch(4)));
    work($h);

    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $id = $queue->pushed()[0]->id;
    $payload = (string) $queue->payload($id);
    expect($payload)->toContain($from);

    // Rewritten in place, as anyone who can write the jobs table could.
    $job = $queue->reserve(['default'], $h->now, 60);
    \assert($job !== null);
    expect($queue->requeue($job, $h->now, str_replace($from, $to, $payload)))->toBeTrue();

    work($h);

    expect($queue->failed())->toHaveCount(1)
        ->and($queue->failed()[0]->error)->toContain('does not verify')
        ->and(array_column(array_filter($h->journal()->entries, static fn (array $e): bool => isset($e['item'])), 'item'))->toBe([0]);
})->with([
    'the checkpoint moved on' => ['"cp":{"next":1}', '"cp":{"next":3}'],
    'the tenant changed under it' => ['"tenant":"acme"', '"tenant":"globex"'],
]);

it('keeps a checkpoint with the payload it was sealed in, wherever the payload is put', function () {
    $h = new Harness();
    $signals = signals($h);
    $signals->stopAfter = 1;
    $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new Batch(4)));
    work($h);
    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $acme = (string) $queue->payload($queue->pushed()[0]->id);

    // globex's own job, its row given acme's paused payload: it runs as
    // what that payload seals -- acme, from acme's checkpoint -- and never
    // as globex.
    $h->orm->actAs('globex', fn () => $h->dispatcher->dispatch(new \Tinvar\Tests\Jobs\Fixtures\RecordContext(note: 'globex')));
    $first = $queue->reserve(['default'], $h->now, 60);
    $globex = $queue->reserve(['default'], $h->now, 60);
    \assert($first !== null && $globex !== null && $globex->id === $queue->pushed()[1]->id);
    $queue->requeue($globex, $h->now, $acme);
    $queue->requeue($first, $h->now);

    work($h);

    expect(array_column(array_filter($h->journal()->entries, static fn (array $e): bool => isset($e['item'])), 'item'))->toBe([0, 1, 2, 3, 2, 3])
        ->and(array_filter($h->journal()->entries, static fn (array $e): bool => ($e['tenant'] ?? null) === 'globex'))->toBe([]);
});

it('takes back the try a requeue gives back, and acts only for the holder', function (\Closure $make) {
    $fixture = new TenantFixture();
    $queue = $make($fixture);
    $queue->push(new PendingJob('j', 'default', 'payload', 0));

    $stale = $queue->reserve(['default'], 100, 60);
    $current = $queue->reserve(['default'], 161, 60);
    \assert($stale !== null && $current !== null);
    expect($current->attempts)->toBe(2)
        ->and($queue->requeue($stale, 0, 'forged'))->toBeFalse()
        ->and($queue->requeue($current, 200, 'checkpointed'))->toBeTrue()
        ->and($queue->reserve(['default'], 199, 60))->toBeNull();

    $again = $queue->reserve(['default'], 200, 60);
    expect($again?->attempts)->toBe(2)
        ->and($again?->payload)->toBe('checkpointed')
        ->and($queue->requeue($again, 200))->toBeTrue()
        ->and($queue->reserve(['default'], 200, 60)?->payload)->toBe('checkpointed');
})->with(queues());

it('holds a checkpoint to plain data, and to its size', function () {
    $h = new Harness();
    $control = $h->runner->control(new Envelope('id', Batch::class, ['items' => 1]));

    expect(fn () => $control->release(checkpoint: ['at' => new \DateTimeImmutable()]))->toThrow(\InvalidArgumentException::class, 'plain data')
        ->and(fn () => $control->release(checkpoint: ['x' => NAN]))->toThrow(\InvalidArgumentException::class, 'finite')
        ->and(fn () => $control->release(checkpoint: ['x' => str_repeat('a', JobControl::MAX_CHECKPOINT_BYTES)]))->toThrow(\LengthException::class)
        ->and(fn () => $control->release(-1))->toThrow(\InvalidArgumentException::class)
        ->and($control->released())->toBeNull();

    $control->release(5, ['ok' => [1, 'two', true, null, 1.5]]);
    expect($control->released())->toBe(['delay' => 5, 'checkpoint' => ['ok' => [1, 'two', true, null, 1.5]]]);
});

it('gives each job its own idempotency key, keyed by the application', function () {
    $h = new Harness();
    $one = $h->runner->control(new Envelope('one', Batch::class, []));
    $two = $h->runner->control(new Envelope('two', Batch::class, []));
    $other = new \Tinvar\Jobs\JobRunner($h->orm, new \Tinvar\Support\Signer('another-key-of-thirty-two-bytes!!!', Envelope::PURPOSE), $h->container->resolver(), $h->auth)
        ->control(new Envelope('one', Batch::class, []));

    expect($one->idempotencyKey())->toBe($h->runner->control(new Envelope('one', Batch::class, [], tenant: 'acme', releases: 3))->idempotencyKey())
        ->and($one->idempotencyKey())->not->toBe($two->idempotencyKey())
        ->and($one->idempotencyKey('charge'))->not->toBe($one->idempotencyKey('email'))
        ->and($one->idempotencyKey())->not->toBe($other->idempotencyKey());
});

it('reads a payload sealed before pausing existed, and seals one that never paused as it did', function () {
    $h = new Harness();
    $envelope = new Envelope('id', Batch::class, ['items' => 1], tenant: 'acme');

    expect($envelope->seal($h->signer))->not->toContain('"cp"')
        ->and(Envelope::open($envelope->seal($h->signer), $h->signer)->releases)->toBe(0)
        ->and(Envelope::open($envelope->paused(null)->seal($h->signer), $h->signer)->releases)->toBe(1);
});

it('runs a sync job that pauses again at once, from its checkpoint', function () {
    $h = new Harness();
    $dispatcher = new \Tinvar\Jobs\Dispatcher(new SyncQueue($h->runner), $h->orm, $h->signer, $h->auth);
    $h->container->get(Signals::class)->stop = null;

    $dispatcher->dispatch(new OnlyPauses());
})->throws(JobRefused::class, 'paused more times');

it('refuses a stop budget that leaves no room for the grace and the margin', function () {
    expect(fn () => new WorkerOptions(stopBudget: StopBudget::MIN - 1))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => StopBudget::parse('70s'))->toThrow(\InvalidArgumentException::class)
        ->and(StopBudget::parse(null))->toBe(70)
        ->and(StopBudget::parse('30'))->toBe(30)
        ->and(WorkerOptions::fromArguments(['--stop-budget=45'])->stopBudget)->toBe(45)
        ->and(WorkerOptions::fromArguments([], stopBudget: 25)->stopBudget)->toBe(25)
        ->and(fn () => WorkerOptions::fromArguments(['--stop-budget=5']))->toThrow(\InvalidArgumentException::class);

    $h = new Harness();
    $h->worker = new Worker($h->queue, $h->runner, $h->scope, graceSeconds: 20);
    expect(fn () => $h->worker->run(new WorkerOptions(stopWhenEmpty: true, signals: false, stopBudget: 20)))
        ->toThrow(\InvalidArgumentException::class, 'no room');
});

it('spends the budget as it says: the interrupt with the grace and margin left, the hand-back with the margin', function () {
    expect(StopBudget::interruptAfter(70))->toBe(70 - Worker::GRACE_SECONDS - StopBudget::STOP_MARGIN)
        ->and(StopBudget::handBackAfter(70))->toBe(67)
        ->and(StopBudget::interruptAfter(70) + Worker::GRACE_SECONDS)->toBe(StopBudget::handBackAfter(70))
        // ADR 0063's numbers, at the default.
        ->and(StopBudget::oneContainerGrace(StopBudget::DEFAULT))->toBe(190)
        ->and(StopBudget::oneContainerServeWait(StopBudget::DEFAULT))->toBe(85)
        ->and(StopBudget::phpContainerGrace(StopBudget::DEFAULT))->toBe(100)
        ->and(StopBudget::podGrace(StopBudget::DEFAULT))->toBe(200);
});

/** Sleeps through the stop, as a job blocked on a slow server does. */
#[QueueOptions(tries: 1, timeout: 600)]
final readonly class SleepsThroughStop implements Job
{
    public function handle(Journal $journal, Signals $signals): void
    {
        $journal->entries[] = ['started' => true];
        if ($signals->stop === null) {
            return;
        }
        $signals->stop();
        $signals->stop = null;
        $until = microtime(true) + 20;
        while (microtime(true) < $until) {
            usleep(20_000);
        }
        $journal->entries[] = ['slept through' => true];
    }
}

it('stops a job blocked through the stop by the real alarm, with the grace and margin left of the budget', function () {
    if (!\function_exists('pcntl_signal')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    $h = new Harness();
    $h->worker = new Worker($h->queue, $h->runner, $h->scope, log: function (string $line) use ($h): void {
        $h->log[] = $line;
    }, clock: fn (): int => $h->now, graceSeconds: 1);
    $signals = signals($h);
    $h->dispatcher->dispatch(new SleepsThroughStop());

    $async = pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static fn () => $h->worker->onAlarm());
    try {
        $started = microtime(true);
        // The least budget for a one-second grace: stopped at 10 - 1 - 3 s.
        work($h, StopBudget::MIN);
        $took = microtime(true) - $started;
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($async);
    }

    expect($took)->toBeGreaterThan(StopBudget::interruptAfter(StopBudget::MIN, 1) - 1)
        ->toBeLessThan(StopBudget::handBackAfter(StopBudget::MIN))
        ->and($h->journal()->entries)->toBe([['started' => true]])
        ->and(implode("\n", $h->log))->toContain('handed back')
        ->and(stored($h)[0] ?? null)->toBe(0);

    work($h);
    expect($h->journal()->entries)->toBe([['started' => true], ['started' => true]])
        ->and($h->queue->size('default'))->toBe(0);
});
