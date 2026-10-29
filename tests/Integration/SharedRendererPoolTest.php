<?php

declare(strict_types=1);

/**
 * "All workers share one renderer pool" (ADR 0095): workers of one parallel
 * run -- here, two processes this test starts as paratest starts its own --
 * find and use one pool, started once and held to the run; and a run
 * directory that is not this user's alone is not used.
 */

namespace Tinvar\Tests\Integration\SharedRendererPool;

use Tinvar\Bench\Shop;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** @return array{path: string, shared: bool, supervisor: int|null, sockets: int} */
function worker(int $token): array
{
    $script = __DIR__ . '/Fixtures/shared-pool-worker.php';
    $env = [...getenv(), 'PARATEST' => '1', 'TEST_TOKEN' => (string) $token];
    $process = proc_open([PHP_BINARY, $script, Shop::entry(), \dirname(Shop::root(), 2)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    \assert(\is_resource($process));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    $found = json_decode($out, true);
    expect($found)->toBeArray("the worker said nothing it could be read by: {$out}{$err}");

    return $found;
}

/** The run directory a worker of this process finds. */
function runDirectory(): string
{
    return '/tmp/tinvar-testrun-' . substr(hash('sha256', posix_geteuid() . ':' . getmypid()), 0, 10);
}

function stopPool(?int $pid, string $directory): void
{
    if ($pid !== null) {
        posix_kill($pid, SIGTERM);
    }
    // The pool's own directory once its renderers are gone, and the run's.
    $deadline = microtime(true) + 10;
    while (glob("{$directory}/*.sock") !== [] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    exec('rm -rf ' . escapeshellarg($directory) . ' ' . escapeshellarg(runDirectory()));
}

it('is found and used by every worker of the run, and started once', function () {
    $first = worker(1);
    try {
        $second = worker(2);

        expect($first['shared'])->toBeTrue()
            ->and($second['path'])->toBe($first['path'])
            ->and($second['supervisor'])->toBe($first['supervisor'], 'the second worker started nothing')
            ->and($first['sockets'])->toBeGreaterThanOrEqual(1)
            ->and(posix_kill((int) $first['supervisor'], 0))->toBeTrue('it outlived the worker that started it');

        $stat = lstat(runDirectory());
        expect($stat['mode'] & 0777)->toBe(0700)
            ->and(fileperms($first['path']) & 0777)->toBe(0700);
    } finally {
        stopPool($first['supervisor'], $first['path']);
    }
});

it('is started again by the next worker once it has gone', function () {
    $first = worker(1);
    try {
        posix_kill((int) $first['supervisor'], SIGKILL);
        usleep(200_000);
        $second = worker(2);

        expect($second['path'])->toBe($first['path'])
            ->and($second['supervisor'])->not->toBe($first['supervisor'])
            ->and($second['sockets'])->toBeGreaterThanOrEqual(1);
    } finally {
        stopPool($second['supervisor'] ?? null, $first['path']);
    }
});

it('stops once the run has gone', function () {
    // A shell is the run here: the worker's parent, gone a moment after it.
    $script = __DIR__ . '/Fixtures/shared-pool-worker.php';
    $out = [];
    exec(
        'env PARATEST=1 TEST_TOKEN=1 /bin/sh -c ' . escapeshellarg(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(Shop::entry())
            . ' ' . escapeshellarg(\dirname(Shop::root(), 2)) . '; sleep 0.2',
        ),
        $out,
    );
    $found = json_decode(implode('', $out), true);
    \assert(\is_array($found));
    expect($found['shared'])->toBeTrue();

    try {
        $deadline = microtime(true) + 10;
        while (posix_kill((int) $found['supervisor'], 0) && microtime(true) < $deadline) {
            usleep(100_000);
        }

        expect(posix_kill((int) $found['supervisor'], 0))->toBeFalse('the pool outlived its run');
    } finally {
        stopPool((int) $found['supervisor'], $found['path']);
        exec('rm -rf ' . escapeshellarg('/tmp/tinvar-testrun-' . substr(hash('sha256', posix_geteuid() . ':' . $found['run']), 0, 10)));
    }
});

// A pid file outlives its process, and the pid may be anyone's of this user's
// by then: nothing is signalled, nor taken for the pool, unless /proc or ps
// says it runs the renderer on this pool's directory.
it('signals no process its record names unless it is the pool', function () {
    $bystander = proc_open(['sleep', '30'], [], $pipes);
    \assert(\is_resource($bystander));
    $pid = proc_get_status($bystander)['pid'];
    try {
        $script = __DIR__ . '/Fixtures/shared-pool-stop.php';
        $env = [...getenv(), 'PARATEST' => '1', 'TEST_TOKEN' => '1'];
        $process = proc_open([PHP_BINARY, $script, Shop::entry(), \dirname(Shop::root(), 2), (string) $pid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        \assert(\is_resource($process));
        $found = json_decode((string) stream_get_contents($pipes[1]), true);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        usleep(100_000);

        expect($found)->toBeArray("the worker said nothing: {$err}");
        \assert(\is_array($found));
        expect($found['shared'])->toBeTrue()
            ->and($found['running'])->toBeFalse('a stranger is not the pool')
            ->and(proc_get_status($bystander)['running'])->toBeTrue('the stranger was signalled');
        stopPool(null, $found['path']);
    } finally {
        proc_terminate($bystander, SIGKILL);
        proc_close($bystander);
    }
});

it('keeps a pool of its own where the run directory is open to others', function () {
    mkdir(runDirectory(), 0755);
    chmod(runDirectory(), 0755);
    try {
        $found = worker(1);

        expect($found['shared'])->toBeFalse()
            ->and($found['path'])->not->toStartWith(runDirectory());
    } finally {
        @rmdir(runDirectory());
    }
});
