<?php

declare(strict_types=1);

/**
 * The point of ApcuThrottleStore: under FrankenPHP, every worker thread
 * counts against the same budget. Four workers each count hits on one key
 * while requests arrive at once; between them they must count every hit
 * exactly once -- 1 to N with no repeats -- which per-worker counters, as
 * ArrayThrottleStore keeps, cannot do.
 *
 * And what it cannot stop: APCu clearing every count once a flood of keys
 * fills it, which the store finds and, built to, fails closed on.
 *
 * Skipped without a FrankenPHP binary.
 */

namespace Tinvar\Tests\Http\ApcuAcrossWorkers;

use Tinvar\Http\ApcuThrottleStore;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

const REQUESTS = 24;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
});

it('counts every hit once across all worker threads', function () use ($root) {
    $directory = sys_get_temp_dir() . '/tinvar-apcu-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $port = new PortFinder()->find('127.0.0.1');

    file_put_contents("{$directory}/counter.php", <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        \$worker = bin2hex(random_bytes(4));
        \$store = new Tinvar\\Http\\ApcuThrottleStore('tinvar:test:{$port}:');
        while (frankenphp_handle_request(static function () use (\$store, \$worker): void {
            [\$count] = \$store->increment('shared', 3600);
            // Long enough that the requests overlap and several threads serve them.
            usleep(100_000);
            echo json_encode(['count' => \$count, 'worker' => \$worker]);
        })) {}
        PHP);

    file_put_contents("{$directory}/Caddyfile", <<<CADDY
        {
            admin off
            persist_config off
            auto_https off
            frankenphp {
                worker {
                    file {$directory}/counter.php
                    num 4
                }
            }
        }
        http://:{$port} {
            bind 127.0.0.1
            root * {$directory}
            rewrite * /counter.php
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

        // All at once, so they are served side by side.
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < REQUESTS; $i++) {
            $handle = curl_init("http://127.0.0.1:{$port}/");
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_TIMEOUT, 20);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
        } while ($running > 0);

        $answers = array_map(static fn (\CurlHandle $h): mixed => json_decode((string) curl_multi_getcontent($h), true), $handles);
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
    } finally {
        proc_terminate($server, SIGTERM);
        proc_close($server);
        exec('rm -rf ' . escapeshellarg($directory));
    }

    $counts = array_column($answers, 'count');
    sort($counts);

    expect($counts)->toBe(range(1, REQUESTS), 'every hit counted once, by whichever thread served it')
        ->and(\count(array_unique(array_column($answers, 'worker'))))->toBeGreaterThan(1, 'more than one worker thread served');
});

// What ApcuThrottleStore cannot stop: APCu, full, clears every entry, a
// count among them. With apc.ttl at 0, its default, a flood of new keys --
// what each new address is -- fills it. Shown on real APCu, kept small: the
// store finds the wipe by the entry it set with no TTL, says so, and, built
// to fail closed, counts the window the wipe reached into as spent.
it('finds APCu cleared by a flood of keys, and counts the window it reached into as spent', function () use ($root) {
    $directory = sys_get_temp_dir() . '/tinvar-apcu-wipe-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $port = new PortFinder()->find('127.0.0.1');

    file_put_contents("{$directory}/flood.php", <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        \$wipes = 0;
        \$store = new Tinvar\\Http\\ApcuThrottleStore(
            'tinvar:test:{$port}:',
            failClosed: true,
            wiped: static function () use (&\$wipes): void {
                \$wipes++;
            },
        );
        while (frankenphp_handle_request(static function () use (\$store, &\$wipes): void {
            [\$before] = \$store->increment('victim', 3600);
            // A flood of addresses, each a key of its own, until APCu clears.
            for (\$i = 0; \$wipes === 0 && \$i < 500_000; \$i++) {
                \$store->increment("flood|ip|{\$i}", 60);
            }
            [\$after] = \$store->increment('victim', 3600);
            echo json_encode(['before' => \$before, 'flood' => \$i, 'wipes' => \$wipes, 'after' => \$after]);
        })) {}
        PHP);

    file_put_contents("{$directory}/Caddyfile", <<<CADDY
        {
            admin off
            persist_config off
            auto_https off
            frankenphp {
                php_ini apc.shm_size 4M
                php_ini apc.ttl 0
                worker {
                    file {$directory}/flood.php
                    num 1
                }
            }
        }
        http://:{$port} {
            bind 127.0.0.1
            root * {$directory}
            rewrite * /flood.php
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

        $handle = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);
        $answer = json_decode((string) curl_exec($handle), true);
    } finally {
        proc_terminate($server, SIGTERM);
        proc_close($server);
        exec('rm -rf ' . escapeshellarg($directory));
    }

    expect($answer)->toBeArray('the worker answered: ' . json_encode($answer))
        ->and($answer['before'])->toBe(1)
        ->and($answer['wipes'])->toBe(1, 'the flood cleared APCu, and the store found it')
        ->and($answer['flood'])->toBeLessThan(500_000)
        ->and($answer['after'])->toBeGreaterThan(ApcuThrottleStore::LOST, 'counted as spent, not as 2 or 1');
});
