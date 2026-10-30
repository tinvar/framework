<?php

declare(strict_types=1);

/**
 * What every queue driver must do, and every one that keeps locks, windows
 * and batches (Coordination): run against the database queue on each
 * database and against the Redis queue, each with a second client as
 * another worker would hold.
 */

namespace Tinvar\Tests\Jobs\Fixtures;

use Tinvar\Queue\BatchRecord;
use Tinvar\Queue\Coordination;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\Queue;
use Tinvar\Queue\Wakes;

/** Queues, reserves, releases and fails as a queue must, $other as a second worker on the same store. */
function behavesAsAQueue(Queue $queue, Queue $other): void
{
    $queue->push(new PendingJob('a', 'default', 'payload-a', 100));
    $queue->push(new PendingJob('b', 'default', 'payload-b', 200));
    $queue->push(new PendingJob('c', 'mail', 'payload-c', 100));

    expect($queue->size('default'))->toBe(2)
        // Nothing before its time.
        ->and($queue->reserve(['default'], 99, 60))->toBeNull();

    // The first queue named that has one, oldest first.
    $first = $queue->reserve(['mail', 'default'], 150, 60);
    expect($first?->id)->toBe('c')->and($first?->attempts)->toBe(1)->and($first?->payload)->toBe('payload-c');

    // A second worker cannot take a held job.
    $second = $other->reserve(['default'], 150, 60);
    expect($second?->id)->toBe('a')
        ->and($queue->reserve(['default'], 150, 60))->toBeNull('b is not available yet, and a is held');

    // Released with a delay, then taken again on a later attempt.
    \assert($second !== null);
    $other->release($second, 300);
    $b = $queue->reserve(['default'], 250, 60);
    expect($b?->id)->toBe('b');
    \assert($b !== null);
    $queue->delete($b);
    $again = $queue->reserve(['default'], 300, 60);
    expect($again?->id)->toBe('a')->and($again?->attempts)->toBe(2);

    // A lapsed reservation is taken by another, and the first holder can
    // then neither delete nor fail it.
    \assert($again !== null);
    $taken = $other->reserve(['default'], 361, 60);
    expect($taken?->id)->toBe('a');
    $queue->delete($again);
    $queue->fail($again, 'late', 400);
    expect($queue->size('default'))->toBe(1)->and($queue->failed())->toBe([]);

    \assert($taken !== null);
    $other->fail($taken, 'boom', 400);
    expect($queue->failed())->toHaveCount(1)
        ->and($queue->failed()[0]->error)->toBe('boom')
        ->and($queue->failed()[0]->payload)->toBe('payload-a')
        ->and($queue->retry('a', 500))->toBeTrue()
        ->and($queue->retry('a', 500))->toBeFalse()
        ->and($queue->reserve(['default'], 500, 60)?->attempts)->toBe(1);

    // Woken (ADR 0119): a job waiting for later made available now, and
    // one a worker holds left as it is.
    if ($queue instanceof Wakes) {
        $queue->push(new PendingJob('w', 'later', 'payload-w', 1_000));
        expect($queue->wake('w', 600))->toBeTrue()
            ->and($queue->wake('w', 600))->toBeFalse('available already')
            ->and($queue->wake('missing', 600))->toBeFalse();
        $woken = $other->reserve(['later'], 600, 60);
        expect($woken?->id)->toBe('w')
            ->and($queue->wake('w', 610))->toBeFalse('held');
        \assert($woken !== null);
        $other->release($woken, 2_000);
        expect($queue->wake('w', 620))->toBeTrue()
            ->and($queue->reserve(['later'], 620, 60)?->id)->toBe('w');
    }
}

/**
 * Locks, windows and batches (ADR 0100, 0118), $other as a second worker
 * on the same store; $inTransaction, where the store has transactions of
 * the caller's, runs its work inside one.
 *
 * @param (\Closure(\Closure(): mixed): mixed)|null $inTransaction
 */
function coordinates(Queue&Coordination $queue, Queue&Coordination $other, ?\Closure $inTransaction = null, bool $sliding = true): void
{
    // A lock: one holder at a time, across clients, until it lapses.
    expect($queue->lock('k', 'job-1', 200, 100))->toBeNull()
        ->and($other->lock('k', 'job-2', 300, 150))->toBe('job-1')
        ->and($other->unlock('k', 'job-2'))->toBeFalse();
    if ($inTransaction !== null) {
        // Held inside a transaction, refused without failing it.
        expect($inTransaction(static fn (): ?string => $queue->lock('k', 'job-3', 300, 150)))->toBe('job-1');
    }
    expect($other->lock('k', 'job-2', 400, 200))->toBeNull()
        ->and($queue->unlock('k', 'job-1'))->toBeFalse()
        ->and($other->unlock('k', 'job-2'))->toBeTrue()
        ->and($queue->lock('k', 'job-4', 500, 210))->toBeNull();

    // A fixed window: its runs across both, then the next; a refusal says
    // how long until the window turns.
    expect($queue->slot('mail', 2, 60, 120))->toBe(0)
        ->and($other->slot('mail', 2, 60, 130))->toBe(0)
        ->and($queue->slot('mail', 2, 60, 170))->toBe(10);
    $other->unslot('mail', 60, 170);
    expect($queue->slot('mail', 2, 60, 175))->toBe(0)
        ->and($queue->slot('mail', 2, 60, 180))->toBe(0)
        ->and($other->slot('mail', 2, 60, 181))->toBe(0)
        // Another key counts on its own.
        ->and($other->slot('t:other', 1, 60, 181))->toBe(0)
        ->and($other->slot('t:other', 1, 60, 182))->toBe(58);

    if ($sliding) {
        // A sliding window: never more than its runs in any 60 seconds, so
        // the edge of a minute lets nothing more through.
        expect($queue->slot('slide', 2, 60, 110, true))->toBe(0)
            ->and($other->slot('slide', 2, 60, 119, true))->toBe(0)
            ->and($queue->slot('slide', 2, 60, 121, true))->toBe(49, 'the minute turned, and 110 is still inside the last 60 seconds')
            ->and($other->slot('slide', 2, 60, 170, true))->toBe(0, '110 has left the window')
            ->and($queue->slot('slide', 2, 60, 171, true))->toBe(8);
        $queue->unslot('slide', 60, 170, true);
        expect($other->slot('slide', 2, 60, 172, true))->toBe(0, 'given back');
    }

    // A batch: counted to its end once, cancelled at its first failure.
    $callbacks = [['on' => BatchRecord::FINALLY, 'job' => new PendingJob('cb', 'default', 'sealed', 0)]];
    $queue->addBatch(new BatchRecord('b-1', 'acme', '', '', 3, 3, 0, false, 100, callbacks: $callbacks));
    $first = $other->batchJobFailed('b-1', 'j-1', 110);
    // Counted once: run again, it moves nothing.
    $again = $queue->batchJobDone('b-1', 'j-1', 115);
    expect($queue->addToBatch('b-1', 1))->toBeFalse('cancelled, it takes no more');
    $second = $queue->batchJobDone('b-1', 'j-2', 120);
    $third = $other->batchJobDone('b-1', 'j-3', 130);
    expect($again?->batch->pending)->toBe(2)
        ->and($again?->finished)->toBeFalse();
    expect($first?->firstFailure)->toBeTrue()
        ->and($first?->batch->cancelledAt)->toBe(110)
        ->and($second?->finished)->toBeFalse()
        ->and($second?->firstFailure)->toBeFalse()
        ->and($third?->finished)->toBeTrue()
        ->and($third?->batch->pending)->toBe(0)
        ->and($third?->batch->failed)->toBe(1)
        ->and($third?->batch->callbacksFor(BatchRecord::FINALLY, 130)[0]->payload ?? null)->toBe('sealed')
        // Past its end, nothing moves and nothing finishes twice.
        ->and($queue->batchJobDone('b-1', 'j-4', 140)?->finished)->toBeFalse();

    // Added to while it runs; not once it has finished.
    $queue->addBatch(new BatchRecord('b-2', 'acme', '', '', 2, 2, 0, true, 100));
    expect($queue->batchJobFailed('b-2', 'j-5', 110)?->batch->cancelledAt)->toBeNull()
        ->and($other->addToBatch('b-2', 2))->toBeTrue()
        ->and($queue->batch('b-2')?->total)->toBe(4)
        ->and($queue->batch('b-2')?->pending)->toBe(3)
        ->and($queue->addToBatch('missing', 1))->toBeFalse();
    foreach (['j-6', 'j-7', 'j-8'] as $job) {
        $queue->batchJobDone('b-2', $job, 150);
    }
    expect($queue->batch('b-2')?->finishedAt)->toBe(150)
        ->and($other->addToBatch('b-2', 1))->toBeFalse('finished, it is not started again');

    // Pruned: the batches finished before 151 and the lapsed lock.
    expect($queue->prune(600, 151))->toBe(3)
        ->and($queue->batch('b-1'))->toBeNull()
        ->and($queue->batch('b-2'))->toBeNull();
}
