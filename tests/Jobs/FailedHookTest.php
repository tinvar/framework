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

#[Queue(tries: 1)]
final readonly class FailsDeepWithASecret implements Job
{
    public function __construct(public string $secret, public string $hidden) {}

    public function handle(): void
    {
        self::charge($this->secret, new Cause($this->hidden));
    }

    private static function charge(string $card, Cause $cause): void
    {
        throw new \RuntimeException('the charge failed', previous: new \LogicException('declined', previous: $cause->raise()));
    }
}

final readonly class Cause
{
    public function __construct(public string $hidden) {}

    public function raise(): \Throwable
    {
        return self::make($this->hidden);
    }

    private static function make(string $hidden): \Throwable
    {
        return new \DomainException('underneath');
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

// Audit: a failed job is kept with its trace, and getTraceAsString() writes
// the first bytes of every frame's arguments wherever
// zend.exception_ignore_args is off -- PHP's development default -- so a
// card number or a token a job handed a function sat in the failed-jobs
// table and showed on queue:failed.
it('keeps a failed job\'s trace without any frame\'s arguments, causes included', function () {
    $ignored = ini_set('zend.exception_ignore_args', '0');
    try {
        $h = new Harness();
        $h->dispatcher->dispatch(new FailsDeepWithASecret('4242secret4242', 'hiddencause99'));
        $h->work();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignored);
    }

    \assert($h->queue instanceof \Tinvar\Queue\ArrayQueue);
    $error = $h->queue->failed()[0]->error;
    expect($error)->not->toContain('4242secret')
        ->not->toContain('hiddencause')
        ->toContain('RuntimeException: the charge failed')
        ->toContain('FailsDeepWithASecret::charge()')
        ->toContain('Caused by LogicException: declined')
        ->toContain('Caused by DomainException: underneath');
});
