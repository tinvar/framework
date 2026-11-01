<?php

declare(strict_types=1);

/**
 * tethered.php: what keeps FrankenPHP from outliving a `tinvar serve` that
 * was killed outright. It runs a command until its standard input closes.
 */

namespace Tinvar\Tests\Server\Tethered;

const TETHER = __DIR__ . '/../../bin/tethered.php';

/**
 * The tether running a PHP snippet, with its standard input held here.
 *
 * @param list<string> $options the tether's own, ahead of the command
 * @return array{resource, resource} the process, and the write end of its standard input
 */
function tethered(string $code, array $options = []): array
{
    $process = proc_open([PHP_BINARY, TETHER, ...$options, PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();

    return [$process, $pipes[0]];
}

/** @param resource $process */
function waitForExit($process, float $seconds): ?int
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return $status['exitcode'];
        }
        usleep(20_000);
    }

    return null;
}

it('stops the command when its standard input closes', function () {
    $pidFile = (string) tempnam(sys_get_temp_dir(), 'tinvar-tethered-');
    [$process, $stdin] = tethered('file_put_contents(' . var_export($pidFile, true) . ', getmypid()); sleep(60);');

    try {
        $deadline = microtime(true) + 5;
        while (filesize($pidFile) === 0 && microtime(true) < $deadline) {
            usleep(20_000);
            clearstatcache();
        }
        $command = (int) file_get_contents($pidFile);

        fclose($stdin);
        $code = waitForExit($process, 5);

        expect($command)->toBeGreaterThan(0)
            ->and($code)->toBe(0, 'the tether stopped')
            ->and(posix_kill($command, 0))->toBeFalse('and so did the command');
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);
        unlink($pidFile);
    }
});

it('exits as the command did while its input is held', function () {
    [$process, $stdin] = tethered('exit(3);');

    try {
        expect(waitForExit($process, 5))->toBe(3);
    } finally {
        fclose($stdin);
        proc_close($process);
    }
});

it('passes a stop signal on to the command', function () {
    [$process, $stdin] = tethered('pcntl_async_signals(true); pcntl_signal(SIGTERM, fn () => exit(7)); sleep(60);');

    try {
        usleep(300_000);
        proc_terminate($process, SIGTERM);

        expect(waitForExit($process, 5))->toBe(7);
    } finally {
        fclose($stdin);
        proc_close($process);
    }
});

// Review: serve gives FrankenPHP ten seconds to stop and then kills the
// tether outright -- which can pass nothing on, so a server held open past
// them by a stream kept serving the port as an orphan. The tether now kills
// the command itself, inside the time it was given, after a stop it passed on.
it('kills a command still running the time it was given after a stop it passed on', function () {
    [$process, $stdin] = tethered('pcntl_async_signals(true); pcntl_signal(SIGTERM, fn () => null); for (;;) { sleep(1); }', ['--kill-after=1']);

    try {
        usleep(300_000);
        proc_terminate($process, SIGTERM);

        expect(waitForExit($process, 4))->toBe(128 + SIGKILL);
    } finally {
        fclose($stdin);
        proc_close($process);
    }
});

it('refuses a time to kill after that is not a number of seconds', function () {
    $process = proc_open([PHP_BINARY, TETHER, '--kill-after=soon', PHP_BINARY, '-r', 'exit(0);'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    try {
        expect(waitForExit($process, 5))->toBe(64);
    } finally {
        proc_close($process);
    }
});
