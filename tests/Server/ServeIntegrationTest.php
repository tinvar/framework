<?php

declare(strict_types=1);

/**
 * `tinvar serve` for real: the shop, the renderer and FrankenPHP in worker
 * mode, on a port the command chose. Started with no --port, read the URL
 * it prints, fetch pages from it, stop it with SIGTERM.
 *
 * Skipped without a FrankenPHP binary or the JavaScript dependencies.
 */

namespace Tinvar\Tests\Server\ServeIntegration;

use Tinvar\Bench\Shop;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
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
 * A running `tinvar serve`, its URL, and a way to stop it.
 *
 * @return array{resource, string, \Closure(): ?int} the process, its URL, and a stop that returns its exit status
 */
function serve(string $root, string $keyFile): array
{
    // What the children say, kept for a failure message.
    $log = tempnam(sys_get_temp_dir(), 'tinvar-serve-log-');
    $owner = getmypid();
    register_shutdown_function(static function () use ($log, $owner): void {
        if (getmypid() === $owner && is_file($log)) {
            unlink($log);
        }
    });

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => $keyFile,
        ],
    );
    stream_set_blocking($pipes[1], false);

    $output = '';
    $deadline = microtime(true) + 60;
    while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $output, $m) !== 1) {
        $output .= (string) stream_get_contents($pipes[1]);
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(50_000);
    }

    $stop = static function () use ($process, $pipes): ?int {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        $deadline = microtime(true) + 20;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $code = $status['running'] ? null : $status['exitcode'];
        if ($status['running']) {
            proc_terminate($process, SIGKILL);
        }
        fclose($pipes[1]);
        proc_close($process);

        return $code;
    };

    if (!isset($m[1])) {
        $stop();
        throw new \RuntimeException(
            "tinvar serve printed no URL:\n{$output}\n" . implode("\n", \array_slice(file($log) ?: [], -20)),
        );
    }

    return [$process, $m[1], $stop];
}

/** @return array{int, array<string, string>, string} status, headers by lowercase name, body */
function fetch(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = (string) file_get_contents($url, false, $context);

    $status = 0;
    $parsed = [];
    foreach (http_get_last_response_headers() ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower($name)] = trim($value);
        }
    }

    return [$status, $parsed, $body];
}

it('serves the shop on a free port it chose, and stops cleanly', function () use ($root) {
    $keyFile = sys_get_temp_dir() . '/tinvar-serve-key-' . bin2hex(random_bytes(4)) . '/app.key';
    [, $url, $stop] = serve($root, $keyFile);

    try {
        $port = (int) parse_url($url, PHP_URL_PORT);
        [$status, $headers, $body] = fetch("{$url}/", ['Accept: text/html']);
        preg_match('#/assets/index-[\w-]+\.css#', $body, $css);
        [$assetStatus, $assetHeaders] = fetch($url . ($css[0] ?? '/assets/missing.css'));
        [$redirect, $redirectHeaders] = fetch("{$url}/orders", ['Accept: text/html']);
    } finally {
        $code = $stop();
        if (is_file($keyFile)) {
            unlink($keyFile);
        }
        foreach (glob(\dirname($keyFile) . '/{,.}*', GLOB_BRACE) ?: [] as $leftover) {
            if (is_file($leftover)) {
                unlink($leftover);
            }
        }
        if (is_dir(\dirname($keyFile))) {
            rmdir(\dirname($keyFile));
        }
    }

    expect($url)->toStartWith('http://127.0.0.1:')
        ->and($port)->toBeGreaterThanOrEqual(6000)->toBeLessThanOrEqual(7000);

    // The catalog, rendered by a worker through the renderer.
    expect($status)->toBe(200)
        ->and($headers['content-security-policy'] ?? '')->toStartWith("script-src 'nonce-")
        ->and($headers)->not->toHaveKey('x-powered-by')
        ->and($body)->toContain('Pellwick Supply')
        ->toContain('data-availability=');

    // Built assets straight from dist/client, cached for a year.
    expect($assetStatus)->toBe(200)
        ->and($assetHeaders['cache-control'] ?? '')->toBe('public, max-age=31536000, immutable');

    expect($redirect)->toBe(302)
        ->and($redirectHeaders['location'] ?? '')->toBe('/sign-in?next=%2Forders');

    // SIGTERM stops both children and exits zero; nothing answers after.
    set_error_handler(static fn (): bool => true);
    try {
        $answers = fsockopen('127.0.0.1', $port, $errno, $error, 0.5);
    } finally {
        restore_error_handler();
    }

    expect($code)->toBe(0)
        ->and($answers)->toBeFalse();
});
