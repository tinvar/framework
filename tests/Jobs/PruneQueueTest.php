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
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\ReservedJob;

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
