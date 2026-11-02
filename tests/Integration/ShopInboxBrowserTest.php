<?php

declare(strict_types=1);

/**
 * The notification bell end to end (ADR 0154): the shop under `tinvar
 * serve --worker` -- FrankenPHP, its built-in Mercure hub, the queue
 * worker -- and Chromium. A signed-in customer has their orders open; one
 * of their orders ships; the worker fans the notification out, and the
 * bell rings on the open page with no reload, over the inbox's personal
 * channel; opening the entry marks it read and goes to the order.
 *
 * Skipped without FrankenPHP, Playwright or the JavaScript dependencies.
 */

namespace Tinvar\Tests\Integration\ShopInboxBrowser;

use Shop\Console\Tasks\Fulfilment\AdvanceShipments;
use Shop\Http\Channels\Orders\OrderChanges;
use Shop\Kernel;
use Shop\Support\DemoAccount;
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
 * `tinvar serve --worker` on the shop with a fresh database and a key this
 * test reads too, for one test.
 *
 * @param \Closure(string, string, string): void $drive given the origin, the database and the key file
 */
function served(string $root, \Closure $drive): void
{
    $keyDir = sys_get_temp_dir() . '/tinvar-inbox-' . bin2hex(random_bytes(4));
    mkdir($keyDir, 0o700);
    $keyFile = "{$keyDir}/app.key";
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-inbox-log-');
    $database = Shop::database(customers: 29);

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2', '--worker'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]), 'SHOP_DATABASE' => $database, 'TINVAR_APP_KEY_FILE' => $keyFile],
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
        expect($m[1] ?? null)->not->toBeNull("tinvar serve printed no URL:\n{$printed}\n" . implode('', \array_slice(file($log) ?: [], -20)));

        $drive($m[1], $database, $keyFile);
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
        foreach (glob("{$keyDir}/{,.}*", GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($keyDir);
    }
}

/** Ships one of the demo customer's orders as the schedule would, queueing its notification for the served worker. */
function shipAnOrder(string $database, string $keyFile, string $origin): string
{
    $previous = getenv('TINVAR_APP_KEY_FILE');
    putenv("TINVAR_APP_KEY_FILE={$keyFile}");
    try {
        $kernel = Kernel::fromEnvironment('/tmp/tinvar-inbox-browser-none.sock', key: Kernel::appKey(), database: $database);
        $connection = $kernel->orm->connection();
        $customer = (string) $connection->select('SELECT id FROM customers WHERE email = ?', [DemoAccount::EMAIL])[0]['id'];
        $order = (string) $connection->select("SELECT id FROM orders WHERE customer_id = ? AND status <> 'cancelled' LIMIT 1", [$customer])[0]['id'];
        // Every other order just placed, so the run ships this one alone.
        $connection->execute("UPDATE orders SET status = 'placed', placed_at = ? WHERE id <> ?", [gmdate('Y-m-d\TH:i:s\Z'), $order]);
        $connection->execute("UPDATE orders SET status = 'packed', placed_at = ? WHERE id = ?", [gmdate('Y-m-d\TH:i:s\Z', time() - 31 * 3600), $order]);

        new AdvanceShipments()->advance($kernel->orm, new OrderChanges(notifier: $kernel->kernel()->notifier, baseUrl: $origin), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $order;
    } finally {
        putenv($previous === false ? 'TINVAR_APP_KEY_FILE' : "TINVAR_APP_KEY_FILE={$previous}");
    }
}

it('rings the bell on the open page when an order ships, with no reload, and marks the entry read when it is opened', function () use ($root) {
    $output = '';
    $order = '';
    served($root, function (string $origin, string $database, string $keyFile) use ($root, &$output, &$order): void {
        $process = proc_open(
            ['node', "{$root}/examples/shop/e2e/inbox.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 120;
        $shipped = false;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]);
            if (!$shipped && str_contains($output, "READY\n")) {
                $order = shipAnOrder($database, $keyFile, $origin);
                $shipped = true;
            }
            usleep(50_000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
            $output .= "\n[timed out]";
        }
        fclose($pipes[1]);
        proc_close($process);
    });

    $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $line): bool => str_starts_with($line, '{')));
    $result = json_decode((string) end($lines), true);
    expect($result)->toBeArray("the browser script failed:\n{$output}")
        ->and($result['errors'])->toBe([], $output)
        ->and($result['before'])->toBe('Notifications')
        ->and($result['rung'])->toBe('Notifications, 1 unread')
        ->and($result['toast'])->toEndWith('has shipped')
        ->and($result['shown']['read'])->toBe('false')
        ->and($result['landed'])->toBe("/orders/{$order}")
        ->and($result['after'])->toBe('Notifications')
        ->and($result['reloads'])->toBe(0)
        // The feed and its personal channel, each let in.
        ->and(array_values(array_unique(array_map(static fn (array $r): string => "{$r['path']} {$r['status']}", $result['inbox']))))
        ->toContain('/_inbox/customer 200', '/_channel/inbox.customer 200', '/_inbox/customer/read 200');
});
