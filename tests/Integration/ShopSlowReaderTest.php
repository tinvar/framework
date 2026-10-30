<?php

declare(strict_types=1);

/**
 * A client that stops reading must not hold up every other visitor's page.
 *
 * FrankenPHP's static binary has the `parallel` extension built in, which
 * puts one process-wide mutex around every write PHP makes to the server
 * (php_parallel_output_function). A worker writing a response that a client
 * has stopped reading waits in that write, holding the mutex, and every other
 * worker's output waits behind it: one slow reader stalled the server
 * (ADR 0051) for as long as `write_idle` let its write stall, and more.
 *
 * So no worker writes to a client (ADR 0053): the shop's Caddyfile holds a
 * response whole while PHP writes it and sends it after, outside the mutex,
 * and hands a stored file to the file server. `write_idle` still cuts the
 * slow reader off.
 *
 * The slow reader asks for the catalog over and over down one connection,
 * with a receive buffer of 512 bytes, and never reads: the first responses
 * fill the kernel's buffers and the next write blocks. A visitor's request
 * beside it must be answered at once, and the slow reader's connection must
 * be cut off before it was sent everything it asked for. Then the same for a
 * receipt of a few megabytes, downloaded by a client that stops reading.
 *
 * Skipped without FrankenPHP, ext-sockets, ext-gd or the JavaScript
 * dependencies.
 */

namespace Tinvar\Tests\Integration\ShopSlowReader;

use Shop\DemoAccount;
use Tinvar\Bench\Shop;
use Tinvar\Bench\SlowReader;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    if (!\extension_loaded('sockets') || !\extension_loaded('gd')) {
        test()->markTestSkipped('ext-sockets or ext-gd is not loaded.');
    }
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * `tinvar serve` on the shop, for one test.
 *
 * @param \Closure(string, int): void $drive given the host and port
 * @param array<string, string> $env
 */
function served(string $root, \Closure $drive, array $env = []): void
{
    $keyDir = sys_get_temp_dir() . '/tinvar-slow-reader-' . bin2hex(random_bytes(4));
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-slow-reader-log-');

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2', '--renderers=1'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            ...$env,
        ],
    );
    stream_set_blocking($pipes[1], false);

    try {
        $printed = '';
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline && preg_match('#Listening on http://(\S+):(\d+)#', $printed, $m) !== 1) {
            $printed .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        expect($m[1] ?? null)->not->toBeNull(
            "tinvar serve printed no URL:\n{$printed}\n" . implode('', \array_slice(file($log) ?: [], -20)),
        );

        $drive($m[1], (int) $m[2]);
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        $deadline = microtime(true) + 20;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        proc_close($process);
        @unlink($log);
        @unlink("{$keyDir}/app.key");
        @unlink("{$keyDir}/.app.key.lock");
        @rmdir($keyDir);
    }
}

/**
 * A GET of the whole response, on a connection of its own.
 *
 * @return array{status: int, bytes: int, seconds: float}|null null if it was not answered in time
 */
function fetch(string $host, int $port, string $path, int $timeout): ?array
{
    $started = microtime(true);
    $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);
    if ($stream === false) {
        return null;
    }
    stream_set_timeout($stream, $timeout);
    fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
    $response = (string) stream_get_contents($stream);
    $timedOut = stream_get_meta_data($stream)['timed_out'];
    fclose($stream);

    // The response, past a 103 Early Hint ahead of it (ADR 0108).
    $response = (string) preg_replace('#^(?:HTTP/1\.1 1\d\d[^\r]*\r\n.*?\r\n\r\n)+#s', '', $response);
    if ($timedOut || preg_match('#^HTTP/1\.1 (\d{3})#', $response, $m) !== 1) {
        return null;
    }

    return ['status' => (int) $m[1], 'bytes' => \strlen($response), 'seconds' => microtime(true) - $started];
}

it('answers other visitors while a client that stopped reading holds a response', function () use ($root) {
    served($root, function (string $host, int $port): void {
        // Warm: both workers booted and the renderer loaded, so what is timed
        // below is the stall and not a cold start.
        $warm = fetch($host, $port, '/', 30);
        expect($warm)->not->toBeNull()
            ->and($warm['status'] ?? null)->toBe(200);

        // Enough requests that what they ask for overflows every buffer
        // between the worker and the slow reader -- a few megabytes -- however
        // large the catalog is. Those past the one that blocks are never
        // rendered: the worker is held in its write.
        $asked = max(8, min(256, intdiv(4 * 1024 * 1024, max(1, $warm['bytes'] ?? 1)) + 1));

        $slow = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        expect($slow)->not->toBeFalse();
        \assert($slow instanceof \Socket);
        socket_set_option($slow, SOL_SOCKET, SO_RCVBUF, 512);
        expect(socket_connect($slow, $host, $port))->toBeTrue();
        $opened = microtime(true);
        $request = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\n\r\n";
        socket_write($slow, str_repeat($request, $asked));

        // Time for its worker to render the first responses and block in the
        // write after them.
        usleep(2_000_000);

        // With the worker writing to the slow reader itself this waited until
        // `write_idle` cut the write off: 7 to 12 s.
        $beside = fetch($host, $port, '/', 40);
        expect($beside)->not->toBeNull('a request beside the slow reader was not answered within 40 s')
            ->and($beside['status'] ?? null)->toBe(200)
            ->and($beside['seconds'] ?? INF)->toBeLessThan(3.0);

        // Past the bound, from the moment the slow reader asked: its write has
        // been aborted. Reading now gets what was buffered and then the end of
        // the connection, not every response it asked for -- a connection
        // never cut off would be sent them all once read.
        $wait = 15.0 - (microtime(true) - $opened);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        socket_set_option($slow, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 10, 'usec' => 0]);
        $received = '';
        $ended = false;
        while (true) {
            $chunk = @socket_read($slow, 65536);
            if ($chunk === false) {
                // Reset by the server, or nothing more within the timeout.
                $ended = \in_array(socket_last_error($slow), [SOCKET_ECONNRESET, SOCKET_EPIPE], true);
                break;
            }
            if ($chunk === '') {
                $ended = true;
                break;
            }
            $received .= $chunk;
        }
        socket_close($slow);

        expect($ended)->toBeTrue('the slow reader\'s connection was not closed by the server')
            ->and(substr_count($received, "HTTP/1.1 200"))->toBeLessThan($asked);
    });
});

it('answers other visitors while a client that stopped reading downloads a receipt, sent by the file server', function () use ($root) {
    $storage = sys_get_temp_dir() . '/tinvar-slow-reader-storage-' . bin2hex(random_bytes(4));
    // A database of its own: the receipt stays on the demo account, which
    // other tests expect to have none.
    $database = Shop::testDatabase();

    try {
        served($root, function (string $host, int $port): void {
            $customer = new SlowReader($host, $port);
            $receipt = $customer->receipt(DemoAccount::EMAIL, DemoAccount::PASSWORD, 'dev');

            // Sent whole, as uploaded, with the headers PHP gave it -- and
            // neither of the two the Caddyfile reads.
            $fetched = $customer->request('GET', $receipt['path']);
            expect($fetched['status'] ?? null)->toBe(200)
                ->and(($fetched['body'] ?? '') === $receipt['bytes'])->toBeTrue('the receipt did not come back as uploaded')
                ->and($fetched['headers']['content-type'] ?? null)->toBe(['image/png'])
                ->and($fetched['headers']['x-content-type-options'] ?? null)->toBe(['nosniff'])
                ->and($fetched['headers']['content-security-policy'] ?? null)->toBe(["default-src 'none'; sandbox"])
                ->and($fetched['headers'])->not->toHaveKey('x-accel-redirect')
                ->and($fetched['headers'])->not->toHaveKey('x-accel-buffering');

            // Only the session its URL was minted for.
            expect((new SlowReader($host, $port))->request('GET', $receipt['path'])['status'] ?? null)->toBe(404);

            $slow = $customer->stopReading($receipt['path'], ['Cookie' => $customer->cookieHeader()], 2);
            usleep(1_000_000);

            // A worker sending the file itself held the mutex for 8 s and more.
            $beside = (new SlowReader($host, $port))->request('GET', '/', [], '', 40);
            socket_close($slow);
            expect($beside['status'] ?? null)->toBe(200)
                ->and($beside['seconds'] ?? INF)->toBeLessThan(3.0);
        }, ['SHOP_STORAGE' => $storage, 'SHOP_DATABASE' => $database]);
    } finally {
        exec('rm -rf ' . escapeshellarg($storage));
    }
});
