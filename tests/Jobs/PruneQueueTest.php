<?php

declare(strict_types=1);

/**
 * The nightly prune (ADR 0118): what `queue:prune` does, scheduled -- the
 * batches finished a week ago and the locks that have lapsed -- and
 * nothing on a queue that keeps neither.
 */

namespace Tinvar\Tests\Jobs\PruneQueue;

use Tinvar\Attributes\Schedule;
use Tinvar\Jobs\PruneQueue;
use Tinvar\Jobs\Rate;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;

require_once __DIR__ . '/Fixtures/jobs.php';

it('forgets batches finished a week ago and lapsed locks, keeping the rest', function () {
    $queue = new ArrayQueue();
    $now = 2_000_000_000;
    $queue->addBatch(new BatchRecord('old', '', '', '', 1, 0, 0, false, 1, finishedAt: $now - PruneQueue::KEEP - 1));
    $queue->addBatch(new BatchRecord('recent', '', '', '', 1, 0, 0, false, 1, finishedAt: $now - 60));
    $queue->addBatch(new BatchRecord('running', '', '', '', 1, 1, 0, false, 1));
    $queue->lock('lapsed', 'j-1', $now - 1, $now - 600);
    $queue->lock('held', 'j-2', $now + 600, $now);

    expect(PruneQueue::prune($queue, $now))->toBe(2)
        ->and($queue->batch('old'))->toBeNull()
        ->and($queue->batch('recent'))->not->toBeNull()
        ->and($queue->batch('running'))->not->toBeNull()
        ->and($queue->locked('lapsed'))->toBeNull()
        ->and($queue->locked('held'))->not->toBeNull();
});

it('forgets, on the array queue, the ends counted for a pruned batch and the rate windows long past', function () {
    $queue = new ArrayQueue();
    $now = 2_000_000_000;
    $then = $now - Rate::MAX_PER;
    $queue->addBatch(new BatchRecord('old', '', '', '', 2, 2, 0, false, 1));
    $queue->batchJobDone('old', 'j-1', $now - PruneQueue::KEEP - 1);
    $queue->batchJobDone('old', 'j-2', $now - PruneQueue::KEEP - 1);
    $queue->addBatch(new BatchRecord('running', '', '', '', 2, 2, 0, false, 1));
    $queue->batchJobDone('running', 'j-3', $now - 10);
    $queue->slot('stale-fixed', 5, 60, $then);
    $queue->slot('stale-sliding', 5, 60, $then, sliding: true);
    $queue->slot('fresh-fixed', 5, 60, $now - 30);
    $queue->slot('fresh-sliding', 5, 60, $now - 30, sliding: true);

    $held = static fn (string $name): array => array_keys((array) new \ReflectionProperty(ArrayQueue::class, $name)->getValue($queue));

    // The batch, its window and its sliding start; the ends go with the batch.
    expect(PruneQueue::prune($queue, $now))->toBe(3)
        ->and($held('ended'))->toBe(["running\0j-3"])
        ->and($held('rates'))->toBe(['fresh-fixed'])
        ->and($held('starts'))->toBe(['fresh-sliding']);
});

it('runs nightly, and does nothing on a queue that keeps no batches or locks', function () {
    $plain = new class implements Queue {
        public function push(PendingJob $job): void {}

        public function reserve(array $queues, int $now, int $reserveFor): ?ReservedJob
        {
            return null;
        }

        public function extend(ReservedJob $job, int $until): bool
        {
            return false;
        }

        public function delete(ReservedJob $job): bool
        {
            return false;
        }

        public function release(ReservedJob $job, int $availableAt): bool
        {
            return false;
        }

        public function requeue(ReservedJob $job, int $availableAt, ?string $payload = null): bool
        {
            return false;
        }

        public function fail(ReservedJob $job, string $error, int $failedAt): bool
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

    $schedule = new \ReflectionClass(PruneQueue::class)->getAttributes(Schedule::class)[0]->newInstance();
    expect(PruneQueue::prune($plain, time()))->toBe(0)
        ->and($schedule->expression)->toBe('23 4 * * *');
});

it('forgets, from queue:prune, the jobs that failed more than --failed-days ago (30)', function () {
    $h = new \Tinvar\Tests\Jobs\Fixtures\Harness();
    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $now = 2_000_000_000;
    foreach (['month' => $now - 31 * 86_400, 'week' => $now - 8 * 86_400, 'today' => $now - 60] as $id => $at) {
        $queue->push(new PendingJob($id, 'default', 'payload', $at));
        $reserved = $queue->reserve(['default'], $at, 60);
        \assert($reserved !== null);
        $queue->fail($reserved, 'it threw', $at);
    }
    $lines = [];
    $output = new \Tinvar\Console\Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line): void {});
    $console = new \Tinvar\Console\Console([], $h->orm, $h->container->resolver(), $h->scope, $h->panels, $h->registry, null, $queue, $h->runner, clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable("@{$now}"));
    $ids = static fn (): array => array_map(static fn ($f): string => $f->id, $queue->failed());

    expect($console->run('queue:prune', [], $output))->toBe(0)
        ->and($ids())->toBe(['today', 'week'])
        ->and($console->run('queue:prune', ['--failed-days=7'], $output))->toBe(0)
        ->and($ids())->toBe(['today'])
        ->and($lines)->toBe(['pruned 1', 'pruned 1'])
        // A usage error, nothing forgotten.
        ->and($console->run('queue:prune', ['--failed-days=x'], $output))->toBe(64)
        ->and($ids())->toBe(['today']);
});
