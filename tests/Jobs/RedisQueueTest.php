<?php

declare(strict_types=1);

/**
 * The Redis queue (ADR 0118): the same contract as the database queue's,
 * against a real redis-server -- one named by TINVAR_TEST_REDIS, or one
 * started for this run from the PATH -- and skipped, saying why, without
 * one. CI runs one as a service.
 */

namespace Tinvar\Tests\Jobs\RedisQueue;

use Tinvar\Jobs\Batch;
use Tinvar\Jobs\Chain;
use Tinvar\Queue\HeldJob;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\RedisQueue;
use Tinvar\Tests\Jobs\Fixtures\AlwaysFails;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Databases;

use function Tinvar\Tests\Jobs\Fixtures\behavesAsAQueue;
use function Tinvar\Tests\Jobs\Fixtures\coordinates;

require_once __DIR__ . '/Fixtures/jobs.php';
require_once __DIR__ . '/Fixtures/contract.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** A prefix of the test's own, its hash tag included, so tests sharing the server never share a key. */
function prefix(): string
{
    return '{tinvar-test-' . bin2hex(random_bytes(4)) . '}:queue:';
}

/** A queue under $prefix on a client of its own, as each worker holds one. */
function queue(string $prefix): RedisQueue
{
    return new RedisQueue(Databases::redisClient(), $prefix);
}

/** Every key a test made, gone. */
function sweep(string $prefix): void
{
    $redis = Databases::redisClient();
    $keys = $redis->keys(str_replace(['{', '}'], ['\{', '\}'], $prefix) . '*');
    if (\is_array($keys) && $keys !== []) {
        $redis->del($keys);
    }
}

describe('against Redis', function () {
    it('queues, reserves, releases and fails as the database queue does', function () {
        $prefix = prefix();
        try {
            behavesAsAQueue(queue($prefix), queue($prefix));
        } finally {
            sweep($prefix);
        }
    });

    it('keeps locks, fixed and sliding windows, and batches across workers', function () {
        $prefix = prefix();
        try {
            coordinates(queue($prefix), queue($prefix));
        } finally {
            sweep($prefix);
        }
    });

    it('takes jobs available in the same second in the order they were pushed', function () {
        $prefix = prefix();
        try {
            $queue = queue($prefix);
            foreach (['z', 'a', 'm'] as $id) {
                $queue->push(new PendingJob($id, 'default', $id, 100));
            }
            $taken = [];
            while (($job = $queue->reserve(['default'], 100, 60)) !== null) {
                $taken[] = $job->id;
            }
            expect($taken)->toBe(['z', 'a', 'm']);
        } finally {
            sweep($prefix);
        }
    });

    it('writes everything atomically() is given together, or nothing', function () {
        $prefix = prefix();
        try {
            $queue = queue($prefix);
            expect(static fn () => $queue->atomically(static function () use ($queue): void {
                $queue->push(new PendingJob('one', 'default', 'p', 100));
                expect($queue->lock('k', 'one', 500, 100))->toBeNull('it reads its own writes');
                expect($queue->lock('k', 'two', 500, 100))->toBe('one');

                throw new \RuntimeException('cut off');
            }))->toThrow(\RuntimeException::class, 'cut off');

            expect($queue->size('default'))->toBe(0)
                ->and($queue->lock('k', 'two', 500, 100))->toBeNull('the lock went with the push');
        } finally {
            sweep($prefix);
        }
    });

    // Two workers ending a batch's last two jobs at once: each reads the
    // count under WATCH, and the one whose EXEC comes second finds the row
    // written since, and counts again from what Redis holds -- so the batch
    // finishes once, with nothing lost.
    it('runs its work again when another client wrote what it read, and finishes a batch once', function () {
        $prefix = prefix();
        try {
            $queue = queue($prefix);
            $other = queue($prefix);
            $queue->addBatch(new \Tinvar\Queue\BatchRecord('b', 'acme', '', '', 2, 2, 0, false, 100));

            $runs = 0;
            $step = $queue->atomically(static function () use ($queue, $other, &$runs): ?\Tinvar\Queue\BatchStep {
                $runs++;
                $step = $queue->batchJobDone('b', 'j-1', 110);
                if ($runs === 1) {
                    // Between this read and its EXEC, the other worker ends the other job.
                    $other->batchJobDone('b', 'j-2', 111);
                }

                return $step;
            });

            expect($runs)->toBe(2)
                ->and($step?->finished)->toBeTrue()
                ->and($queue->batch('b')?->pending)->toBe(0)
                ->and($queue->batch('b')?->finishedAt)->toBe(110);
        } finally {
            sweep($prefix);
        }
    });

    it('hands back a held job by its id and token alone, as serve does for a killed worker', function () {
        $prefix = prefix();
        try {
            $queue = queue($prefix);
            $queue->push(new PendingJob('held', 'mail', 'p', 100));
            $job = $queue->reserve(['mail'], 100, 60);
            \assert($job !== null);

            $file = sys_get_temp_dir() . '/tinvar-held-' . bin2hex(random_bytes(4));
            HeldJob::write($file, 4242, $job);
            $read = HeldJob::read($file);
            unlink($file);
            \assert($read !== null);

            expect($read['job']->queue)->toBe('')
                ->and(queue($prefix)->requeue($read['job'], 100))->toBeTrue()
                ->and($queue->reserve(['mail'], 100, 60)?->attempts)->toBe(1, 'its try given back');
        } finally {
            sweep($prefix);
        }
    });

    it('runs jobs, a chain and a batch through a worker, each in the tenant it was sealed in', function () {
        $prefix = prefix();
        try {
            $fixture = new TenantFixture();
            $queue = queue($prefix);
            $h = new Harness($queue, $fixture);

            $h->orm->actAs('acme', fn () => $h->dispatcher->dispatch(new RecordContext(note: 'redis')));
            $h->dispatcher->chain(Chain::of(new RecordContext(note: 'first'), new RecordContext(note: 'second')));
            $h->dispatcher->batch(Batch::of(new RecordContext(note: 'in batch'))->then(new RecordContext(note: 'then')));
            $h->dispatcher->dispatch(new AlwaysFails('redis'));
            for ($round = 0; $round < 4; $round++) {
                $h->work();
                $h->now += 60;
            }

            $notes = array_column($h->journal()->entries, 'note');
            expect($h->journal()->entries[0])->toMatchArray(['note' => 'redis', 'tenant' => 'acme'])
                ->and($notes)->toContain('first', 'second', 'in batch', 'then')
                ->and(array_search('second', $notes, true))->toBeGreaterThan(array_search('first', $notes, true))
                ->and($queue->size('default'))->toBe(0)
                ->and($queue->failed())->toHaveCount(1);
        } finally {
            sweep($prefix);
        }
    });

    it('keeps nothing of a job in a key name: an id or queue that could split one is refused', function () {
        $prefix = prefix();
        try {
            $queue = queue($prefix);
            expect(static fn () => $queue->push(new PendingJob('a:b', 'default', 'p', 1)))->toThrow(\InvalidArgumentException::class)
                ->and(static fn () => $queue->push(new PendingJob('a', 'mail:x', 'p', 1)))->toThrow(\InvalidArgumentException::class)
                ->and(static fn () => $queue->reserve(['ma il'], 1, 60))->toThrow(\InvalidArgumentException::class);
        } finally {
            sweep($prefix);
        }
    });
})->skip(Databases::redisMissing() !== null, Databases::redisMissing() ?? '');

it('refuses a prefix with spaces or none', function () {
    expect(static fn () => new RedisQueue(static fn (): \Redis => new \Redis(), ''))->toThrow(\InvalidArgumentException::class)
        ->and(static fn () => new RedisQueue(static fn (): \Redis => new \Redis(), 'a b'))->toThrow(\InvalidArgumentException::class);
})->skip(!RedisQueue::available(), 'ext-redis is not loaded.');
