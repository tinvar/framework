<?php

declare(strict_types=1);

/**
 * A reserved job is its holder's alone, for as long as it can run.
 *
 * Reserved for the worker's timeout, a job declaring a longer one used to
 * outlive its reservation while still running, and a second worker took it
 * and ran it again beside the first: a second charge, a second email. And
 * a worker that finished after losing its reservation must not mark done,
 * failed or due again a job another worker now holds.
 */

namespace Tinvar\Tests\Queue\Reservation;

use Tinvar\Container\Dependency;
use Tinvar\Attributes\Queue as QueueOptions;
use Tinvar\Container\Lifetime;
use Tinvar\Jobs\Job;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;

use function Tinvar\Tests\Jobs\Fixtures\databaseQueue;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

/** Another worker, reaching for jobs while one runs. */
#[Dependency(Lifetime::Worker)]
final class SecondWorker
{
    /** @var (\Closure(): ?ReservedJob)|null */
    public ?\Closure $reach = null;

    public ?ReservedJob $took = null;
}

/** Declares a longer timeout than the worker's, and meets a second worker while it runs. */
#[QueueOptions(tries: 1, timeout: 120)]
final readonly class RunsLong implements Job
{
    public function handle(SecondWorker $second, Journal $journal): void
    {
        $second->took = ($second->reach ?? static fn () => null)();
        $journal->entries[] = ['second worker took it' => $second->took !== null];
    }
}

function queues(): array
{
    return [
        'in memory' => [static fn (TenantFixture $f): Queue => new ArrayQueue()],
        'SQLite' => [static fn (TenantFixture $f): Queue => databaseQueue($f->connection)],
    ];
}

it('holds a job with a longer timeout than the worker\'s for as long as it can run', function (\Closure $make) {
    $fixture = new TenantFixture();
    $h = new Harness($make($fixture), $fixture);
    $second = $h->container->get(SecondWorker::class);
    // 100 seconds in: past the worker's 60 and the margin, inside the
    // job's 120 and the margin.
    $second->reach = fn (): ?ReservedJob => $h->queue->reserve(['default'], $h->now + 100, 60);
    $h->dispatcher->dispatch(new RunsLong());

    $h->work();

    expect($h->journal()->entries)->toBe([['second worker took it' => false]])
        ->and($h->queue->size('default'))->toBe(0);
})->with(queues());

it('records nothing for a worker whose reservation another worker has taken over', function (\Closure $make) {
    $fixture = new TenantFixture();
    $h = new Harness($make($fixture), $fixture);
    $second = $h->container->get(SecondWorker::class);
    // Long past any reservation, as when a worker is paused for an hour.
    $second->reach = fn (): ?ReservedJob => $h->queue->reserve(['default'], $h->now + 10_000, 60);
    $h->dispatcher->dispatch(new RunsLong());

    $h->work();

    // The first worker's run finished, and was not recorded as done: the
    // job is the second worker's, still held by it, and only it can end it.
    expect($h->journal()->entries)->toBe([['second worker took it' => true]])
        ->and(implode("\n", $h->log))->toContain(' lost ')
        ->and($h->queue->size('default'))->toBe(1)
        ->and($second->took)->not->toBeNull()
        ->and($h->queue->delete($second->took))->toBeTrue()
        ->and($h->queue->size('default'))->toBe(0);
})->with(queues());

it('lets only the holder of the current token extend, delete, release or fail a job', function (\Closure $make) {
    $fixture = new TenantFixture();
    $queue = $make($fixture);
    $queue->push(new PendingJob('j', 'default', 'payload', 0));

    $stale = $queue->reserve(['default'], 100, 60);
    $current = $queue->reserve(['default'], 161, 60);
    \assert($stale !== null && $current !== null);

    expect($queue->extend($stale, 1000))->toBeFalse()
        ->and($queue->delete($stale))->toBeFalse()
        ->and($queue->release($stale, 0))->toBeFalse()
        ->and($queue->fail($stale, 'late', 200))->toBeFalse()
        ->and($queue->failed())->toBe([])
        // Still held by the current holder: nobody else can take it.
        ->and($queue->reserve(['default'], 200, 60))->toBeNull()
        ->and($queue->extend($current, 1000))->toBeTrue()
        ->and($queue->reserve(['default'], 999, 60))->toBeNull()
        ->and($queue->delete($current))->toBeTrue()
        ->and($queue->size('default'))->toBe(0);
})->with(queues());

it('refuses a grace that would outlast the reservation margin', function () {
    $h = new Harness();

    expect(fn () => new \Tinvar\Queue\Worker($h->queue, $h->runner, $h->scope, graceSeconds: \Tinvar\Queue\Worker::RESERVATION_MARGIN))
        ->toThrow(\InvalidArgumentException::class, 'shorter than the reservation margin');
});
