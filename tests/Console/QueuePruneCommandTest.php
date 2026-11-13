<?php

declare(strict_types=1);

/**
 * `queue:prune` forgets failed jobs too (ADR 0221): their payloads, the
 * jobs' arguments in the clear, are kept for `queue:retry` for a month, not
 * for good.
 */

namespace Tinvar\Tests\Console\QueuePrune;

use Tinvar\Console\Console;
use Tinvar\Console\Output;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Queue\PendingJob;
use Tinvar\Tests\Jobs\Fixtures\Harness;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

it('forgets the jobs that failed more than --failed-days ago (30)', function () {
    $h = new Harness();
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
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line): void {});
    $console = new Console([], $h->orm, $h->container->resolver(), $h->scope, $h->panels, $h->registry, null, $queue, $h->runner, clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable("@{$now}"));
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
