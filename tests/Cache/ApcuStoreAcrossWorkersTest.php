<?php

declare(strict_types=1);

/**
 * The point of ApcuStore: under FrankenPHP, every worker thread reads the
 * entries any of them put, and misses them once any of them forgets a tag.
 * One request puts an entry; requests arriving at once, served by several
 * threads, all hit it; one request forgets its tag; requests arriving at
 * once all miss -- which per-worker entries, as ArrayStore keeps them,
 * cannot do.
 *
 * Skipped without a FrankenPHP binary.
 */

namespace Tinvar\Tests\Cache\ApcuStoreAcrossWorkers;

use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

const REQUESTS = 16;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
});

/**
 * Sends $count requests at once and returns their answers.
 *
 * @return list<array{worker: string, answer: mixed}>
 */
function atOnce(string $url, int $count): array
{
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 20);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0);

    $answers = [];
    foreach ($handles as $handle) {
        $answers[] = json_decode((string) curl_multi_getcontent($handle), true);
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);

    return $answers;
}

it('shares entries, and forgets them, across all worker threads', function () use ($root) {
    $directory = sys_get_temp_dir() . '/tinvar-apcu-cache-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $port = new PortFinder()->find('127.0.0.1');

    file_put_contents("{$directory}/cache.php", <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        \$worker = bin2hex(random_bytes(4));
        \$store = new Tinvar\\Cache\\ApcuStore('tinvar:test:{$port}:');
        while (frankenphp_handle_request(static function () use (\$store, \$worker): void {
            \$op = \$_GET['op'] ?? 'get';
            if (\$op === 'put') {
                \$store->put('q:shared', ['by' => \$worker], 600, ['racks']);
                \$answer = 'put';
            } elseif (\$op === 'forget') {
                \$answer = \$store->forgetTags(['racks']);
            } elseif (\$op === 'flush') {
                \$store->flush();
                \$answer = 'flushed';
            } else {
                \$answer = \$store->get('q:shared');
            }
            // Long enough that the requests overlap and several threads serve them.
            usleep(100_000);
            echo json_encode(['worker' => \$worker, 'answer' => \$answer]);
        })) {}
        PHP);

    file_put_contents("{$directory}/Caddyfile", <<<CADDY
        {
            admin off
            persist_config off
            auto_https off
            frankenphp {
                worker {
                    file {$directory}/cache.php
                    num 4
                }
            }
        }
        http://:{$port} {
            bind 127.0.0.1
            root * {$directory}
            rewrite * /cache.php
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

        $base = "http://127.0.0.1:{$port}/";
        $put = atOnce("{$base}?op=put", 1)[0];
        $before = atOnce("{$base}?op=get", REQUESTS);
        atOnce("{$base}?op=forget", 1);
        $after = atOnce("{$base}?op=get", REQUESTS);

        // Put again under the version the forget moved to, then flushed.
        atOnce("{$base}?op=put", 1);
        $again = atOnce("{$base}?op=get", 1)[0];
        atOnce("{$base}?op=flush", 1);
        $flushed = atOnce("{$base}?op=get", REQUESTS);
    } finally {
        proc_terminate($server, SIGTERM);
        proc_close($server);
        exec('rm -rf ' . escapeshellarg($directory));
    }

    expect(array_column($before, 'answer'))->toBe(array_fill(0, REQUESTS, ['hit' => true, 'value' => ['by' => $put['worker']]]))
        ->and(\count(array_unique(array_column($before, 'worker'))))->toBeGreaterThan(1, 'more than one worker thread hit it')
        ->and(array_column($after, 'answer'))->toBe(array_fill(0, REQUESTS, ['hit' => false, 'value' => null]))
        ->and(\count(array_unique(array_column($after, 'worker'))))->toBeGreaterThan(1, 'more than one worker thread missed it')
        ->and($again['answer']['hit'])->toBeTrue()
        ->and(array_column($flushed, 'answer'))->toBe(array_fill(0, REQUESTS, ['hit' => false, 'value' => null]));
});
