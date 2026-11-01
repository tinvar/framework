<?php

declare(strict_types=1);

/**
 * Live updates end to end: the shop under `tinvar serve` -- FrankenPHP in
 * worker mode, its built-in Mercure hub, the renderer -- and Chromium.
 *
 * A customer places an order in one tab; their orders and the figures above
 * them update in another tab, and staff's list of every customer's orders
 * updates in a browser of their own, with no reload. php -S, which the other
 * browser tests use, has no hub, so this one runs the server production
 * runs.
 *
 * Skipped without FrankenPHP, Playwright or the JavaScript dependencies.
 */

namespace Tinvar\Tests\Integration\ShopLive;

use Shop\Support\DemoAccount;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    if (!is_file("{$root}/node_modules/playwright/package.json")) {
        test()->markTestSkipped('Playwright is not installed.');
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
 * Runs a command and returns its combined output, killing it past the
 * deadline: a browser that hangs must fail the test, not hang it.
 *
 * @param list<string> $command
 */
function runWithTimeout(array $command, string $cwd, int $seconds): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    stream_set_blocking($pipes[1], false);

    $output = '';
    $deadline = microtime(true) + $seconds;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        $output .= (string) stream_get_contents($pipes[1]);
        usleep(50_000);
    }
    $output .= (string) stream_get_contents($pipes[1]);

    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
        $output .= "\n[timed out after {$seconds}s]";
    }
    proc_close($process);

    return $output;
}

/**
 * `tinvar serve` on the shop with a fresh database, for one test.
 *
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, \Closure $drive): void
{
    $keyDir = sys_get_temp_dir() . '/tinvar-live-' . bin2hex(random_bytes(4));
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-live-log-');

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            // Its own database -- placing an order writes to it -- by a
            // customer count no other test asks for.
            'SHOP_DATABASE' => Shop::database(customers: 23),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
        ],
    );
    stream_set_blocking($pipes[1], false);

    try {
        $printed = '';
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $printed, $m) !== 1) {
            $printed .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        expect($m[1] ?? null)->not->toBeNull(
            "tinvar serve printed no URL:\n{$printed}\n" . implode('', \array_slice(file($log) ?: [], -20)),
        );

        $drive($m[1]);
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

it('updates another tab and staff\'s list when an order is placed, without a reload', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout([
            'node',
            "{$root}/examples/shop/e2e/live.mjs",
            $origin,
            DemoAccount::EMAIL,
            DemoAccount::PASSWORD,
            StaffAccount::EMAIL,
            StaffAccount::PASSWORD,
        ], $root, 90);
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['number'])->toBeString();

    // The customer's other tab: the new order at the top and the figures
    // drawn again -- one background request for the page segment its loader
    // read the tag in, claiming none of the #[Once] content it keeps -- in
    // the same document, with no entry added to its history.
    $other = $result['other'];
    expect($other['after']['first'])->toBe($result['number'])
        ->and($other['before']['first'])->not->toBe($result['number'])
        ->and((int) str_replace(',', '', (string) $other['after']['orders']))
        ->toBe((int) str_replace(',', '', (string) $other['before']['orders']) + 1)
        ->and($other['sameDocument'])->toBeTrue()
        ->and($other['sameEntries'])->toBeTrue()
        ->and($other['documents'])->toBe(0)
        ->and($other['refetches'])->toHaveCount(1)
        ->and($other['refetches'][0])->toMatchArray(['path' => '/orders', 'only' => 'page', 'once' => null])
        // It traded its token for the hub's access, then listened.
        ->and($other['live'])->toBe([
            ['path' => '/_live/subscribe', 'status' => 200],
            ['path' => '/.well-known/mercure', 'status' => 200],
        ]);

    // Staff's list of everyone's orders, in another browser altogether.
    expect($result['admin']['after']['first'])->toBe($result['number'])
        ->and($result['admin']['sameDocument'])->toBeTrue()
        ->and($result['admin']['documents'])->toBe(0)
        ->and($result['admin']['refetches'])->toHaveCount(1)
        ->and($result['admin']['refetches'][0])->toMatchArray(['path' => '/admin/orders', 'only' => 'page']);

    // The hub's access token is a cookie for its path alone, which script
    // cannot read.
    expect($result['hubCookie'])->toBe([
        ['path' => '/.well-known/mercure', 'httpOnly' => true, 'secure' => true, 'sameSite' => 'Strict'],
    ])->and($result['readable'])->toBeFalse();
});
