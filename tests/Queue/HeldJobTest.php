<?php

declare(strict_types=1);

/**
 * The job a worker holds, written down for whatever stops it: a job blocked
 * in a read runs no PHP until the read returns, so neither the worker's
 * stop nor its alarm reaches it, and the worker is killed holding it. serve
 * then hands it back with `queue:hand-back` -- once the worker is gone, and
 * only while the token is still the job's.
 */

namespace Tinvar\Tests\Queue\HeldJob;

use Tinvar\Console\Console;
use Tinvar\Console\Output;
use Tinvar\Container\Dependency;
use Tinvar\Container\Lifetime;
use Tinvar\Jobs\Job;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\HeldJob;
use Tinvar\Queue\PendingJob;
use Tinvar\Queue\ReservedJob;
use Tinvar\Jobs\Worker;
use Tinvar\Jobs\WorkerOptions;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

#[Dependency(Lifetime::Worker)]
final class Paths
{
    public string $held = '';
}

/** Reads what its worker wrote down while it runs. */
final readonly class ReadsHeld implements Job
{
    public function handle(Journal $journal, Paths $paths): void
    {
        $journal->entries[] = ['held' => (string) @file_get_contents($paths->held)];
    }
}

function heldFile(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-held-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    return "{$directory}/queue-work.held";
}

/** A pid that was a process a moment ago and is not now. */
function deadPid(): int
{
    $process = proc_open(['true'], [], $pipes);
    \assert(\is_resource($process));
    $pid = proc_get_status($process)['pid'];
    proc_close($process);

    return $pid;
}

/** @return array{Console, Output, \Closure(): list<string>} */
function console(Harness $h): array
{
    $err = [];
    $output = new Output(static function (string $line): void {}, static function (string $line) use (&$err): void {
        $err[] = $line;
    });

    return [
        new Console([], $h->orm, $h->container->resolver(), $h->scope, $h->panels, $h->registry, null, $h->queue, $h->runner),
        $output,
        function () use (&$err): array {
            return $err;
        },
    ];
}

it('writes down the job in hand while it runs, and clears it once it is recorded', function () {
    $h = new Harness();
    $file = heldFile();
    $h->container->get(Paths::class)->held = $file;
    $h->worker = new Worker($h->queue, $h->runner, $h->scope, clock: fn (): int => $h->now, held: $file);
    $id = $h->dispatcher->dispatch(new ReadsHeld());

    $h->worker->run(new WorkerOptions(stopWhenEmpty: true, signals: false));

    $held = $h->journal()->entries[0]['held'];
    expect($held)->toMatch('/^' . getmypid() . ' ' . preg_quote($id, '/') . ' [a-f0-9]{16,64}\n\z/')
        ->and(is_file($file))->toBeFalse();
});

it('hands back a job whose worker was killed holding it, with its try given back', function () {
    $h = new Harness();
    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $queue->push(new PendingJob('job-1', 'default', 'payload', $h->now));
    $job = $queue->reserve(['default'], $h->now, 60);
    \assert($job !== null);
    $file = heldFile();
    expect(HeldJob::write($file, deadPid(), $job))->toBeTrue();
    [$console, $output, $err] = console($h);

    expect($console->run('queue:hand-back', [$file], $output))->toBe(0)
        ->and($err())->toBe(['Handed back job-1, which a worker killed at the stop held.'])
        ->and($queue->attempts('job-1'))->toBe(0)
        ->and($queue->reservedUntil('job-1'))->toBeNull()
        ->and(is_file($file))->toBeFalse();
});

it('hands back nothing while the worker that held it still runs: it would run twice', function () {
    $h = new Harness();
    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $queue->push(new PendingJob('job-1', 'default', 'payload', $h->now));
    $job = $queue->reserve(['default'], $h->now, 60);
    \assert($job !== null);
    $file = heldFile();
    HeldJob::write($file, getmypid(), $job);
    [$console, $output, $err] = console($h);

    expect($console->run('queue:hand-back', [$file], $output))->toBe(1)
        ->and($err()[0])->toContain('is still running')
        ->and($queue->attempts('job-1'))->toBe(1)
        ->and($queue->reservedUntil('job-1'))->not->toBeNull();
});

it('leaves alone a job taken since by another worker, and a file that is not what a worker writes', function () {
    $h = new Harness();
    $queue = $h->queue;
    \assert($queue instanceof ArrayQueue);
    $queue->push(new PendingJob('job-1', 'default', 'payload', $h->now));
    $stale = $queue->reserve(['default'], $h->now, 60);
    $current = $queue->reserve(['default'], $h->now + 61, 60);
    \assert($stale !== null && $current !== null);
    $file = heldFile();
    HeldJob::write($file, deadPid(), $stale);
    [$console, $output, $err] = console($h);

    expect($console->run('queue:hand-back', [$file], $output))->toBe(0)
        ->and($err()[0])->toContain('no longer held')
        ->and($queue->reservedUntil('job-1'))->toBe($h->now + 121);

    foreach (["1 job-1\n", "x job-1 abcdefabcdefabcdef\n", "1 ../../etc abcdefabcdefabcdef\n", ''] as $text) {
        file_put_contents($file, $text);
        expect(HeldJob::read($file))->toBeNull();
    }
    expect(HeldJob::write($file, 1, new ReservedJob('a b', 'default', '', 1, 'abcdefabcdefabcdef')))->toBeFalse()
        ->and($console->run('queue:hand-back', [$file . '.missing'], $output))->toBe(0);
});
