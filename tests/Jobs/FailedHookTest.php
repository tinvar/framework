<?php

declare(strict_types=1);

/**
 * A job's failed() hook takes its services and its JobControl as handle()
 * does, beside the error (ADR 0119) -- what a workflow's record is ended
 * with -- and is refused, not handed something built, when it asks for an
 * error of another kind than the one thrown.
 */

namespace Tinvar\Tests\Jobs\FailedHook;

use Tinvar\Attributes\Queue;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobControl;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Jobs\Fixtures\Journal;

require_once __DIR__ . '/Fixtures/jobs.php';

#[Queue(tries: 1)]
final readonly class FailsAndSays implements Job
{
    public function handle(): void
    {
        throw new \RuntimeException('it broke');
    }

    public function failed(\Throwable $error, Journal $journal, JobControl $job): void
    {
        $journal->entries[] = ['failed' => $error->getMessage(), 'id' => $job->id()];
    }
}

#[Queue(tries: 1)]
final readonly class AsksForAnotherError implements Job
{
    public function handle(): void
    {
        throw new \RuntimeException('it broke');
    }

    public function failed(\LogicException $error, Journal $journal): void
    {
        $journal->entries[] = ['failed' => 'should not run'];
    }
}

#[Queue(tries: 1)]
final readonly class TakesItUntyped implements Job
{
    public function handle(): void
    {
        throw new \RuntimeException('untyped');
    }

    /** @param \Throwable $error */
    public function failed($error): void
    {
        Seen::$errors[] = $error->getMessage();
    }
}

final class Seen
{
    /** @var list<string> */
    public static array $errors = [];
}

it('hands failed() the error, its services and its own JobControl', function () {
    $h = new Harness();
    $id = $h->dispatcher->dispatch(new FailsAndSays());
    $h->work();

    expect($h->journal()->entries)->toBe([['failed' => 'it broke', 'id' => $id]]);
});

it('runs a failed() that takes the error untyped, as it always did', function () {
    Seen::$errors = [];
    $h = new Harness();
    $h->dispatcher->dispatch(new TakesItUntyped());
    $h->work();

    expect(Seen::$errors)->toBe(['untyped']);
});

it('refuses a failed() typed for another kind of error, rather than build it one', function () {
    $h = new Harness();
    $h->dispatcher->dispatch(new AsksForAnotherError());
    $h->work();

    expect($h->journal()->entries)->toBe([])
        ->and(implode("\n", $h->log))->toContain('failed');
});
