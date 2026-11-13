<?php

declare(strict_types=1);

/**
 * What reaches a worker, measured against the pinned FrankenPHP rather than
 * stand-ins (audit 2026-11-13, M5 and M6): a Tinvar\Http\Worker behind a
 * Caddyfile holding the skeleton's limits, its deadlines shortened so the
 * test is quick.
 *
 *  - A body that names a length over Sapi::MAX_BODY_BYTES is refused with
 *    a 413 before any of it is sent (ADR 0207).
 *  - A client sending its body slowly holds the one worker no longer than
 *    `read_body`; before ADR 0207, as many as there were workers stopped
 *    the app for as long as they liked.
 *  - A header named with `_` never reaches PHP: FrankenPHP drops it, so
 *    `X_Forwarded_For` cannot pass for X-Forwarded-For, which a front
 *    proxy overwrites (M6, refuted here and held).
 *
 * Skipped without a FrankenPHP binary.
 */

namespace Tinvar\Tests\Http\RequestEdgeAtTheServer;

use Tinvar\Http\Sapi;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
});

/**
 * Runs $test against one worker under FrankenPHP, its port handed in.
 *
 * @param \Closure(int): void $test
 */
function served(string $root, \Closure $test): void
{
    $directory = sys_get_temp_dir() . '/tinvar-edge-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o700);
    $port = new PortFinder()->find('127.0.0.1');

    file_put_contents("{$directory}/worker.php", <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        new Tinvar\\Http\\Worker(static fn (): Tinvar\\Http\\Application => new class implements Tinvar\\Http\\Application {
            public function handle(Tinvar\\Http\\Request \$request, string \$rawBody = ''): Tinvar\\Http\\Response
            {
                return Tinvar\\Http\\Response::make(200, json_encode([
                    'bytes' => strlen(\$rawBody),
                    'forwarded' => \$request->header('x-forwarded-for'),
                ]), ['content-type' => 'application/json']);
            }

            public function reset(): void {}
        })->run();
        PHP);

    file_put_contents("{$directory}/Caddyfile", <<<CADDY
        {
            admin off
            persist_config off
            auto_https off
            servers {
                timeouts {
                    read_header 2s
                    read_body 2s
                    write_idle 8s
                }
            }
            frankenphp {
                # As in production: PHP's own warnings -- a body over
                # post_max_size is one -- go to the log, not the response.
                php_ini display_errors 0
                worker {
                    file {$directory}/worker.php
                    num 1
                }
            }
        }
        http://:{$port} {
            bind 127.0.0.1
            request_body {
                max_size 11MiB
            }
            root * {$directory}
            rewrite * /worker.php
            php
        }
        CADDY);

    $server = proc_open(
        [FrankenPhp::locate($root), 'run', '--config', "{$directory}/Caddyfile", '--adapter', 'caddyfile'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$directory}/frankenphp.log", 'w']],
        $pipes,
        $directory,
    );

    try {
        set_error_handler(static fn (): bool => true);
        try {
            $deadline = microtime(true) + 20;
            while (($probe = fsockopen('127.0.0.1', $port, $errno, $error, 0.2)) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        expect($probe)->not->toBeFalse('FrankenPHP did not start: ' . (string) @file_get_contents("{$directory}/frankenphp.log"));
        fclose($probe);

        $test($port);
    } finally {
        proc_terminate($server, SIGTERM);
        proc_close($server);
        exec('rm -rf ' . escapeshellarg($directory));
    }
}

/**
 * Sends $head and then $body to the server, and reads its answer.
 *
 * @return array{status: int, body: string, seconds: float}
 */
function exchange(int $port, string $head, string $body = '', float $wait = 10.0): array
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);
    expect($socket)->not->toBeFalse();
    stream_set_timeout($socket, (int) ceil($wait));
    $started = microtime(true);
    fwrite($socket, $head . $body);
    $answer = '';
    while (!feof($socket) && microtime(true) - $started < $wait) {
        $chunk = fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            if (stream_get_meta_data($socket)['timed_out']) {
                break;
            }
            continue;
        }
        $answer .= $chunk;
        if (str_ends_with($answer, "\r\n0\r\n\r\n") || (preg_match('/content-length: (\d+)/i', $answer, $length) === 1
            && \strlen(explode("\r\n\r\n", $answer, 2)[1] ?? '') >= (int) $length[1])) {
            break;
        }
    }
    fclose($socket);

    [$headers, $body] = explode("\r\n\r\n", $answer, 2) + ['', ''];
    if (preg_match('/^transfer-encoding: chunked/mi', $headers) === 1) {
        $whole = '';
        while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $size) === 1 && hexdec($size[1]) > 0) {
            $whole .= substr($body, \strlen($size[0]), (int) hexdec($size[1]));
            $body = substr($body, \strlen($size[0]) + (int) hexdec($size[1]) + 2);
        }
        $body = $whole;
    }

    return [
        'status' => preg_match('/^HTTP\/1\.1 (\d{3})/', $headers, $status) === 1 ? (int) $status[1] : 0,
        'body' => $body,
        'seconds' => microtime(true) - $started,
    ];
}

it('refuses a body naming a length over the ceiling before any of it is sent, and reads one within it', function () use ($root) {
    served($root, function (int $port): void {
        $over = Sapi::MAX_BODY_BYTES + 1;
        $refused = exchange($port, "POST /a HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: {$over}\r\n\r\n", wait: 5.0);

        expect($refused['status'])->toBe(413)
            ->and($refused['seconds'])->toBeLessThan(1.0);

        // Nor is the one worker kept waiting for the body it refused: PHP
        // drains a body left unread unless its input was opened.
        $waiting = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);
        fwrite($waiting, "POST /a HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: {$over}\r\n\r\n");
        usleep(300_000);
        $next = exchange($port, "GET /b HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", wait: 5.0);
        fclose($waiting);
        expect($next['status'])->toBe(200)
            ->and($next['seconds'])->toBeLessThan(1.0);

        $taken = exchange($port, "POST /a HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: 9\r\nConnection: close\r\n\r\n", '{"a":"b"}');
        expect($taken['status'])->toBe(200)
            ->and(json_decode($taken['body'], true)['bytes'])->toBe(9);
    });
});

it('lets a client sending its body slowly hold the worker no longer than read_body', function () use ($root) {
    served($root, function (int $port): void {
        // The one worker, taken by a body that never comes.
        $slow = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 2);
        fwrite($slow, "POST /a HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: 1000\r\n\r\n0123456789");
        usleep(300_000);

        $next = exchange($port, "GET /b HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", wait: 15.0);
        fclose($slow);

        expect($next['status'])->toBe(200)
            ->and($next['seconds'])->toBeLessThan(5.0);
    });
});

it('never hands PHP a header named with an underscore, so none passes for X-Forwarded-For', function () use ($root) {
    served($root, function (int $port): void {
        $underscored = exchange($port, "GET /c HTTP/1.1\r\nHost: x\r\nX_Forwarded_For: 6.6.6.6\r\nX-Forwarded_For: 6.6.6.7\r\nConnection: close\r\n\r\n");
        $dashed = exchange($port, "GET /c HTTP/1.1\r\nHost: x\r\nX-Forwarded-For: 5.5.5.5\r\nConnection: close\r\n\r\n");

        expect(json_decode($underscored['body'], true)['forwarded'])->toBeNull()
            ->and(json_decode($dashed['body'], true)['forwarded'])->toBe('5.5.5.5');
    });
});
