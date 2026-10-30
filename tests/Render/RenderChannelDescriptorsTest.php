<?php

declare(strict_types=1);

/**
 * The channel in a process holding more than FD_SETSIZE descriptors.
 *
 * A FrankenPHP worker shares its process's descriptor table with every
 * connection the server holds. The channel waited on the renderer's socket
 * with stream_select(), which refuses a descriptor numbered 1024 or more, so
 * once the server held about a thousand connections every render failed as
 * `render_incomplete` -- found holding 2,500 browsers in a load test. Here
 * the descriptors below the socket are files instead of connections.
 */

namespace Tinvar\Tests\Render\Descriptors;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

/** Descriptors to open before the socket: enough to number it past 1024. */
const HELD = 1_100;

/**
 * A renderer that reads the whole render frame -- $expect bytes of it, once
 * its length prefix and type are counted -- after $delayMs, then sends
 * $frames and waits to be hung up on.
 *
 * @param list<array<string, mixed>> $frames
 */
function renderer(string $path, int $expect, array $frames, int $delayMs = 0): int
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
                stream_set_timeout($client, 10);
                usleep($delayMs * 1000);
                for ($read = 0; $read < $expect && !feof($client);) {
                    $read += \strlen((string) fread($client, 65536));
                }
                foreach ($frames as $frame) {
                    fwrite($client, $codec->encodeFrame($frame));
                }
                $until = microtime(true) + 10;
                while (!feof($client) && microtime(true) < $until) {
                    @fread($client, 65536);
                }
                fclose($client);
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

/**
 * Renders $frame against a renderer started with the rest, with HELD files
 * open in this process first.
 *
 * @param array<string, mixed> $frame
 * @param list<array<string, mixed>> $frames
 * @param \Closure(string): void $sink
 */
function renderPastTheLimit(array $frame, array $frames, float $deadline, \Closure $sink, int $delayMs = 0): mixed
{
    $path = sys_get_temp_dir() . '/tinvar-fds-' . bin2hex(random_bytes(6)) . '.sock';
    $codec = new MessagePackCodec();
    $pid = renderer($path, \strlen($codec->encodeFrame($frame)), $frames, $delayMs);

    $held = [];
    try {
        for ($i = 0; $i < HELD; $i++) {
            $held[] = fopen('/dev/null', 'r');
        }

        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $channel = new RenderChannel(
            $path,
            new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
            $codec,
            deadlineSeconds: $deadline,
        );

        return $channel->render($frame, [], $sink);
    } finally {
        foreach ($held as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
}

beforeEach(function () {
    if (!\function_exists('pcntl_fork') || !\function_exists('posix_getrlimit')) {
        $this->markTestSkipped('pcntl and posix are needed.');
    }

    // Room for the files and the test's own descriptors, within the hard
    // limit; a soft limit of 256, as launchd gives, would stop well short.
    $limits = posix_getrlimit();
    $soft = $limits['soft openfiles'] ?? 0;
    $hard = $limits['hard openfiles'] ?? 0;
    $wanted = HELD + 512;
    if ($soft !== 'unlimited' && (int) $soft < $wanted) {
        if ($hard !== 'unlimited' && (int) $hard < $wanted) {
            $this->markTestSkipped("The descriptor limit ({$hard}) is below {$wanted}.");
        }
        posix_setrlimit(POSIX_RLIMIT_NOFILE, $wanted, $hard === 'unlimited' ? -1 : (int) $hard);
    }
});

it('renders with the socket numbered past FD_SETSIZE', function () {
    $page = '';
    $result = renderPastTheLimit(
        ['t' => 'render', 'data' => ['x' => 1]],
        [Frame::chunk('<html>rendered</html>'), ['t' => Frame::DONE]],
        5.0,
        function (string $bytes) use (&$page): void {
            $page .= $bytes;
        },
    );

    expect($page)->toBe('<html>rendered</html>')
        ->and($result->bytes)->toBe(\strlen('<html>rendered</html>'));
});

it('writes a frame larger than the socket buffer to a renderer slow to read it', function () {
    // Two megabytes, read only after 200 ms: the send waits for room.
    $result = renderPastTheLimit(
        ['t' => 'render', 'data' => ['blob' => str_repeat('x', 2 * 1024 * 1024)]],
        [Frame::chunk('ok'), ['t' => Frame::DONE]],
        5.0,
        static function (string $bytes): void {},
        delayMs: 200,
    );

    expect($result->bytes)->toBe(2);
});

it('still gives up at the deadline on a renderer that never reads', function () {
    $started = microtime(true);

    // The renderer reads nothing for ten seconds; the send waits its 0.5 s.
    expect(fn () => renderPastTheLimit(
        ['t' => 'render', 'data' => ['blob' => str_repeat('x', 2 * 1024 * 1024)]],
        [],
        0.5,
        static function (string $bytes): void {},
        delayMs: 10_000,
    ))->toThrow(RendererException::class, '0.5s deadline');
    expect(microtime(true) - $started)->toBeLessThan(3.0);
});

it('still gives up at the deadline on a renderer that never answers', function () {
    $started = microtime(true);

    expect(fn () => renderPastTheLimit(['t' => 'render'], [], 0.3, static function (string $bytes): void {}))
        ->toThrow(RendererException::class, '0.3s deadline');
    expect(microtime(true) - $started)->toBeLessThan(2.0);
});
