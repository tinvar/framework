<?php

declare(strict_types=1);

/**
 * `tinvar serve` with TINVAR_RENDERER_USER set (ADR 0028): it starts no
 * renderers, and holds the pool the renderers' user runs to that user
 * before FrankenPHP starts. Fail closed: a pool answered by anyone else --
 * here, a renderer of this test's own user, the only kind it can start
 * without root -- stops serve at once, with nothing started.
 *
 * Run for real, as a subprocess, for the signal handlers serve installs.
 */

namespace Tinvar\Tests\Server\ServeRendererUser;

use Tinvar\Codec\MessagePackCodec;
use Tinvar\Protocol\Frame;

$root = \dirname(__DIR__, 4);

it('refuses to serve when the pool is answered by anyone but the renderers\' user', function () use ($root) {
    $dir = '/tmp/tvsru-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/pool", 0700, true);
    $socket = "{$dir}/pool/renderer-0-1.sock";
    $server = stream_socket_server("unix://{$socket}");
    \assert($server !== false);
    // As a renderer sharing it with a group makes it, so the refusal is the
    // peer's and not the socket's mode.
    chmod($socket, 0660);
    // The hello answered, as a renderer answers it, and a health check
    // after it, should it ever be asked: it is not.
    $answerer = pcntl_fork();
    if ($answerer === 0) {
        while (($client = @stream_socket_accept($server, 10)) !== false) {
            fread($client, 4096);
            fwrite($client, new MessagePackCodec()->encodeFrame(['t' => Frame::READY]));
            fread($client, 4096);
            fwrite($client, new MessagePackCodec()->encodeFrame(['t' => Frame::HEALTHY, 'pid' => getmypid(), 'inFlight' => 0, 'served' => 0]));
            fclose($client);
        }
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);
    // Never run: serve stops before FrankenPHP.
    file_put_contents("{$dir}/frankenphp", "#!/bin/sh\nexit 1\n");
    chmod("{$dir}/frankenphp", 0700);

    $started = microtime(true);
    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=1'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TINVAR_RENDERER_USER' => 'daemon',
            'TINVAR_SOCKET' => "{$dir}/pool",
            'TINVAR_FRANKENPHP' => "{$dir}/frankenphp",
        ],
    );
    \assert(\is_resource($process));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);
    $took = microtime(true) - $started;

    posix_kill($answerer, SIGKILL);
    pcntl_waitpid($answerer, $ignored);
    exec('rm -rf ' . escapeshellarg($dir));

    expect($status)->toBe(1)
        ->and($out)->not->toContain('Listening on')
        ->and($err)->toContain("The renderers' pool is refused: Refusing the renderer at {$socket}")
        ->and($err)->toContain(PHP_OS_FAMILY === 'Linux' ? 'it is answered by a process of uid ' . posix_geteuid() : 'that cannot be verified')
        // At once, not after waiting out the pool's start.
        ->and($took)->toBeLessThan(10.0);
})->skip(fn () => !\function_exists('pcntl_fork') || posix_geteuid() === 0 || posix_getpwnam('daemon') === false || !is_file($root . '/examples/shop/dist/rsc/index.js'), 'needs pcntl, a user daemon, the shop built, and not root');
