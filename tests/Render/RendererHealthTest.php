<?php

declare(strict_types=1);

/**
 * renderer:check's question, asked of real renderers and of ones that
 * answer wrongly: a health frame alone on its connection, answered with
 * one 'healthy' frame.
 */

namespace Tinvar\Tests\Render\RendererHealth;

use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Render\RendererException;
use Tinvar\Render\RendererHealth;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A fake renderer that answers whatever it is sent with $frames, once. */
function answering(string $path, array $frames): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = @stream_socket_accept($server, 5);
        if ($client !== false) {
            fread($client, 65536);
            foreach ($frames as $frame) {
                fwrite($client, $codec->encodeFrame($frame));
            }
            usleep(200_000);
            fclose($client);
        }
        // Gone without running destructors: an inherited database
        // connection would hang up on the parent's socket.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);

    return $pid;
}

function scratchDirectory(): string
{
    $dir = '/tmp/tvhealth-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    return $dir;
}

function removeDirectory(string $dir, int $pid = 0): void
{
    if ($pid > 0) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    foreach (glob("{$dir}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

it('hears from a real renderer: its pid, the renders open, the renders finished', function () {
    $renderer = Renderer::start('msgpack', 'health');
    try {
        $answer = new RendererHealth()->check($renderer->socketPath);
    } finally {
        $renderer->stop();
    }

    expect($answer['pid'])->toBeGreaterThan(0)
        ->and($answer['inFlight'])->toBe(0)
        ->and($answer['served'])->toBe(0);
});

it('hears from every renderer in a pool, each a process of its own', function () {
    $renderer = Renderer::start('msgpack', 'health-pool', processes: 2);
    try {
        $answers = new RendererHealth()->checkAll($renderer->socketPath);
    } finally {
        $renderer->stop();
    }

    expect($answers)->toHaveCount(2);
    $pids = array_map(static fn (array|string $a): int => \is_array($a) ? $a['pid'] : 0, array_values($answers));
    expect(array_unique($pids))->toHaveCount(2)
        ->and($pids)->not->toContain(0);
});

it('reports a renderer that is not there, beside those that are', function () {
    $dir = scratchDirectory();
    $dead = stream_socket_server("unix://{$dir}/renderer-0-1.sock");
    fclose($dead ?: throw new \RuntimeException('no socket'));
    $pid = answering("{$dir}/renderer-1-2.sock", [['t' => Frame::HEALTHY, 'pid' => 7, 'inFlight' => 1, 'served' => 9]]);

    try {
        $answers = new RendererHealth()->checkAll($dir);
    } finally {
        removeDirectory($dir, $pid);
    }

    expect($answers["{$dir}/renderer-0-1.sock"])->toBeString()->toContain('Connection refused')
        ->and($answers["{$dir}/renderer-1-2.sock"])->toBe(['pid' => 7, 'inFlight' => 1, 'served' => 9]);
});

// The renderer is the semi-trusted side: an answer is checked as any frame
// from it is.
it('refuses an answer that is not one', function (array $frames, string $exception, string $message) {
    $dir = scratchDirectory();
    $pid = answering("{$dir}/r.sock", $frames);

    try {
        expect(fn () => new RendererHealth(timeoutSeconds: 1.0)->check("{$dir}/r.sock"))->toThrow($exception, $message);
    } finally {
        removeDirectory($dir, $pid);
    }
})->with([
    'a render frame' => [[['t' => Frame::CHUNK, 'b' => 'x']], ProtocolException::class, "must be answered with a 'healthy' frame"],
    'a frame PHP sends' => [[['t' => Frame::HEALTH]], ProtocolException::class, "may not send a 'health' frame"],
    'nothing' => [[], RendererException::class, 'closed the connection without answering'],
]);

// The timeout bounded each read, not the answer: a renderer sending its
// answer a byte at a time, each within the timeout, held renderer:check for
// as long as it liked -- 4 KiB of answer at three seconds a byte is hours.
it('gives up on an answer that trickles in past the timeout', function () {
    $dir = scratchDirectory();
    $server = stream_socket_server("unix://{$dir}/r.sock") ?: throw new \RuntimeException('no socket');
    $pid = pcntl_fork();
    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5);
        if ($client !== false) {
            fread($client, 65536);
            $answer = new MessagePackCodec()->encodeFrame(['t' => Frame::HEALTHY, 'pid' => 7, 'inFlight' => 0, 'served' => 0]);
            foreach (str_split($answer) as $byte) {
                fwrite($client, $byte);
                usleep(200_000);
            }
            fclose($client);
        }
        // Gone without running destructors: an inherited database
        // connection would hang up on the parent's socket.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);

    $started = microtime(true);
    try {
        expect(fn () => new RendererHealth(timeoutSeconds: 0.5)->check("{$dir}/r.sock"))
            ->toThrow(RendererException::class, 'no answer within 0.5s');
    } finally {
        removeDirectory($dir, $pid);
    }

    expect(microtime(true) - $started)->toBeLessThan(1.5);
});
