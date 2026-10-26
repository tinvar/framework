<?php

declare(strict_types=1);

/**
 * The channel against a renderer that misbehaves on purpose. The renderer
 * runs third-party npm code, so each of these is something a compromised
 * dependency could do to the trusted process.
 */

namespace Tinvar\Tests\Render\Hardening;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

/**
 * A renderer that accepts, optionally reads the render frame, sends the given
 * frames, then holds the connection open without reading until PHP hangs up.
 *
 * @param list<array<string, mixed>> $frames
 */
function hostileRenderer(string $path, array $frames, bool $readsRenderFrame = true): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $codec = new MessagePackCodec();
            $client = stream_socket_accept($server, 5);
            if ($client !== false) {
                if ($readsRenderFrame) {
                    stream_set_timeout($client, 5);
                    fread($client, 65536);
                }
                foreach ($frames as $frame) {
                    @fwrite($client, $codec->encodeFrame($frame));
                }
                // Never read again; just wait to be hung up on.
                $until = microtime(true) + 10;
                while (microtime(true) < $until && !feof($client)) {
                    usleep(20_000);
                    $meta = stream_get_meta_data($client);
                    if ($meta['eof']) {
                        break;
                    }
                }
                @fclose($client);
            }
        } finally {
            fclose($server);
            // Gone without running destructors: an inherited database
            // connection would hang up on the parent's socket.
            posix_kill(getmypid(), SIGKILL);
            exit(0);
        }
    }

    fclose($server);

    return $pid;
}

function hardeningChannel(string $path, float $deadline = 5.0): RenderChannel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: $deadline,
    );
}

function privateDirectory(): string
{
    $dir = sys_get_temp_dir() . '/tinvar-hardening-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    return $dir;
}

/** @return \Closure(): void */
function cleanUp(string $dir, int $pid = 0): \Closure
{
    return static function () use ($dir, $pid): void {
        if ($pid > 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    };
}

// A renderer that stops reading used to hold the worker on a blocking write
// for as long as it liked; enough of them took every worker the site had.
it('gives up on a renderer that stops reading, at the render deadline', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $pid = hostileRenderer($path, [], readsRenderFrame: false);
    $done = cleanUp($dir, $pid);

    $started = microtime(true);
    try {
        expect(fn () => hardeningChannel($path, deadline: 1.0)->render(
            ['t' => 'render', 'data' => ['blob' => str_repeat('x', 8 * 1024 * 1024)]],
            [],
            static function (string $bytes): void {},
        ))->toThrow(RendererException::class);
        expect(microtime(true) - $started)->toBeLessThan(4.0);
    } finally {
        $done();
    }
});

// An empty query batch did no work by count, so a stream of them was a
// round trip each with nothing to stop it.
it('counts an empty query batch against the call budget', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $frames = array_fill(0, RenderChannel::MAX_QUERY_CALLS + 1, ['t' => Frame::QUERY, 'q' => []]);
    $pid = hostileRenderer($path, $frames);
    $done = cleanUp($dir, $pid);

    try {
        expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
            ->toThrow(RendererException::class, 'query calls');
    } finally {
        $done();
    }
});

it('refuses more meta frames than one render may send', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $frames = array_fill(0, RenderChannel::MAX_META_FRAMES + 1, ['t' => Frame::META, 'status' => 200]);
    $pid = hostileRenderer($path, $frames);
    $done = cleanUp($dir, $pid);

    try {
        expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
            ->toThrow(ProtocolException::class, 'more meta');
    } finally {
        $done();
    }
});

it('refuses meta frames larger in total than one render may send', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $frames = array_fill(0, 3, ['t' => Frame::META, 'title' => str_repeat('x', 100_000)]);
    $pid = hostileRenderer($path, $frames);
    $done = cleanUp($dir, $pid);

    try {
        expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
            ->toThrow(ProtocolException::class, 'more meta');
    } finally {
        $done();
    }
});

// Measured as JSON, a frame's bytes that are not UTF-8 counted as `null`:
// three frames of 100 KB, each opening with one such byte, were measured at
// a few dozen bytes and all held.
it('counts meta bytes that are not UTF-8 against the total', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $frames = [
        ...array_fill(0, 3, ['t' => Frame::META, 'title' => "\xff" . str_repeat('x', 100_000)]),
        ['t' => Frame::DONE],
    ];
    $pid = hostileRenderer($path, $frames);
    $done = cleanUp($dir, $pid);

    try {
        expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
            ->toThrow(ProtocolException::class, 'more meta');
    } finally {
        $done();
    }
});

describe('the socket path', function () {
    // Whoever answers there receives the visitor's loader data and writes
    // the HTML they are served.
    it('refuses a socket whose directory others can write to', function () {
        $dir = privateDirectory();
        $path = $dir . '/r.sock';
        $pid = hostileRenderer($path, []);
        $done = cleanUp($dir, $pid);
        chmod($dir, 0777);

        try {
            expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
                ->toThrow(RendererException::class, 'writable by other users');
        } finally {
            $done();
        }
    });

    // /tmp: shared, but sticky, so only the socket's owner can replace it.
    it('accepts its own socket in a shared sticky directory', function () {
        $dir = privateDirectory();
        $path = $dir . '/r.sock';
        $pid = hostileRenderer($path, [['t' => Frame::DONE]]);
        $done = cleanUp($dir, $pid);
        chmod($dir, 01777);

        try {
            $result = hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {});

            expect($result->chunks)->toBe(0);
        } finally {
            $done();
        }
    });
})->skip(fn () => !\function_exists('posix_geteuid') || posix_geteuid() === 0, 'needs POSIX ownership, and not root');

// A health check's answer is the renderer's to send, but never in a render:
// one arriving mid-render is the renderer saying something nobody asked.
it('refuses a healthy frame during a render', function () {
    $dir = privateDirectory();
    $path = $dir . '/r.sock';
    $pid = hostileRenderer($path, [['t' => Frame::HEALTHY, 'pid' => 1, 'inFlight' => 0, 'served' => 0]]);
    $done = cleanUp($dir, $pid);

    try {
        expect(fn () => hardeningChannel($path)->render(['t' => 'render'], [], static function (string $bytes): void {}))
            ->toThrow(ProtocolException::class, "'healthy' frame during a render");
    } finally {
        $done();
    }
});
