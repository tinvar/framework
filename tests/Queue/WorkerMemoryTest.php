<?php

declare(strict_types=1);

/**
 * `queue:work --memory=MB`: when a worker stops so a fresh process can take
 * over.
 *
 * The limit is on what the process holds once a job is done. A worker that
 * starts past it -- an application that boots big, or a worker inside a
 * process that already holds more, as a test suite's does -- stopped after
 * every job, so its supervisor gave up restarting it; it is held instead to
 * growing by the limit from where it started. What the process holds is
 * handed in, so each case is exact.
 */

namespace Tinvar\Tests\Queue\WorkerMemory;

use Tinvar\Jobs\Worker;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\RecordContext;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const MB = 1024 * 1024;

/**
 * A worker over $h's queue that reads what the process holds from $held, one
 * value a reading, the last repeated.
 *
 * @param list<int> $held in MB: at the start, then after each job
 * @param list<string> $log
 */
function worker(Harness $h, array $held, array &$log): Worker
{
    return new Worker(
        $h->queue,
        $h->runner,
        $h->scope,
        log: static function (string $line) use (&$log): void {
            $log[] = $line;
        },
        clock: fn (): int => $h->now,
        memory: static function () use (&$held): int {
            $mb = \count($held) > 1 ? array_shift($held) : $held[0];

            return $mb * MB;
        },
    );
}

function queued(Harness $h, int $jobs): Harness
{
    for ($n = 1; $n <= $jobs; $n++) {
        $h->dispatcher->dispatch(new RecordContext(note: "job {$n}"));
    }

    return $h;
}

it('stops after the job that takes the process past its limit', function () {
    $h = queued(new Harness(), 3);
    $log = [];

    $ran = worker($h, [40, 60, 130, 140], $log)->run(new WorkerOptions(memoryMb: 128, stopWhenEmpty: true, signals: false));

    expect($ran)->toBe(2)
        ->and($h->queue->size('default'))->toBe(1)
        ->and(end($log))->toBe('stopping: past 128 MB, for a fresh process to take over');
});

it('holds a worker that starts past its limit to growing by it, rather than stopping after each job', function () {
    $h = queued(new Harness(), 3);
    $log = [];

    $ran = worker($h, [300, 310, 320, 330], $log)->run(new WorkerOptions(memoryMb: 128, stopWhenEmpty: true, signals: false));

    expect($ran)->toBe(3)
        ->and($h->queue->size('default'))->toBe(0)
        ->and($log[0])->toBe('started at 300 MB, past its 128 MB limit: it stops once it has grown by 128 MB');

    $h = queued(new Harness(), 3);
    $log = [];

    $ran = worker($h, [300, 420, 429, 440], $log)->run(new WorkerOptions(memoryMb: 128, stopWhenEmpty: true, signals: false));

    expect($ran)->toBe(2)
        ->and(end($log))->toBe('stopping: grown by more than 128 MB, for a fresh process to take over');
});

it('never stops for memory with no limit', function () {
    $h = queued(new Harness(), 3);
    $log = [];

    expect(worker($h, [4000], $log)->run(new WorkerOptions(memoryMb: 0, stopWhenEmpty: true, signals: false)))->toBe(3)
        ->and(implode("\n", $log))->not->toContain('MB');
});
