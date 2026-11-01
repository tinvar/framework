<?php

declare(strict_types=1);

/**
 * The shop's background work end to end: `tinvar serve --worker --schedule`
 * -- FrankenPHP, the renderer, a queue worker and the scheduler -- and
 * Chromium.
 *
 * A customer places an order; the queue worker serve started renders the
 * confirmation through the renderer and sends it, which this reads back
 * from the log transport. The order's page stays open while the shop's
 * orders:advance command, outside the server as the scheduler is, ships and
 * delivers it: the page shows so with no reload, told through the live hub.
 *
 * Skipped without FrankenPHP, Playwright or the JavaScript dependencies.
 */

namespace Tinvar\Tests\Integration\ShopBackground;

use Shop\Support\DemoAccount;
use Tinvar\Bench\Shop;
use Tinvar\Live\HubSettings;
use Tinvar\Mail\LogTransport;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;
use Tinvar\Support\PrivateDirectory;

$root = \dirname(__DIR__, 4);

const KEY = 'shop-background-test-key-of-32-bytes!';

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
 * Runs a command with $env and returns its combined output, killing it past
 * the deadline: a browser that hangs must fail the test, not hang it.
 *
 * @param list<string>          $command
 * @param array<string, string> $env
 */
function runWithTimeout(array $command, string $cwd, array $env, int $seconds): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
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

it('sends the order confirmation from the worker, and tells the open order page when it ships', function () use ($root) {
    $directory = PrivateDirectory::create('tinvar-bg');
    $mailLog = "{$directory}/mail.log";
    $serveLog = "{$directory}/serve.log";
    // Its own database -- placing an order and advancing it write to it --
    // by a customer count no other test asks for.
    $database = Shop::database(customers: 31);
    $env = [
        ...array_diff_key(getenv(), ['TINVAR_APP_KEY_FILE' => true]),
        'TINVAR_APP_KEY' => KEY,
        'SHOP_DATABASE' => $database,
        'SHOP_MAIL_LOG' => $mailLog,
    ];

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2', '--worker', '--schedule'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $serveLog, 'w']],
        $pipes,
        $root,
        $env,
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
        $origin = $m[1] ?? null;
        expect($origin)->not->toBeNull("tinvar serve printed no URL:\n{$printed}\n" . (string) file_get_contents($serveLog));
        \assert(\is_string($origin));

        // The logo the email links to, hosted as a PNG.
        $logo = file_get_contents("{$origin}/brand/mark-64.png", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $logoType = implode("\n", http_get_last_response_headers() ?? []);

        // The browser, and orders:advance run from it as the scheduler runs:
        // a process beside the server, with its address and its hub's keys.
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/background.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD, PHP_BINARY, "{$root}/bin/tinvar"],
            $root,
            [...$env, ...HubSettings::resolve(KEY)->environment(), 'TINVAR_URL' => $origin],
            90,
        );
        $result = json_decode($output, true);
        expect($result)->toBeArray("the browser script failed:\n{$output}\n" . (string) file_get_contents($serveLog));

        // The worker sends the confirmation within a few seconds of the order.
        $sent = [];
        $until = microtime(true) + 20;
        while (microtime(true) < $until) {
            $sent = array_values(array_filter(
                LogTransport::read($mailLog),
                static fn (array $message): bool => str_contains((string) $message['subject'], (string) $result['number']),
            ));
            if ($sent !== []) {
                break;
            }
            usleep(200_000);
        }
        $served = (string) file_get_contents($serveLog);
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
        $exit = proc_close($process);
        PrivateDirectory::remove($directory);
    }

    expect($logo)->toStartWith("\x89PNG")
        ->and(strtolower($logoType))->toContain('content-type: image/png');

    expect($result['errors'])->toBe([])
        ->and($result['number'])->toBeString()
        // Every order six days on is delivered: the seeded ones in flight too.
        ->and($result['advanced'])->toMatch('/^[1-9]\d* delivered$/m');

    // The order's page, shipped and delivered while it was open: its status
    // and its timeline drawn again in the same document, by the refetch the
    // live hub asked for -- the page segment, not the poll's -- and no
    // document load.
    expect($result['before']['delivered'])->toBeFalse()
        ->and($result['after']['delivered'])->toBeTrue()
        ->and($result['sameDocument'])->toBeTrue()
        ->and($result['documents'])->toBe(0)
        ->and(array_filter($result['refetches'], static fn (array $r): bool => $r['path'] === $result['path'] && $r['only'] === 'page'))->not->toBe([]);

    // The confirmation, rendered through the renderer by the worker serve
    // started, links on the address serve gave it, never a Host header.
    expect($sent)->toHaveCount(1, "no confirmation was sent:\n{$served}")
        ->and($sent[0]['subject'])->toMatch('/^Order ' . preg_quote((string) $result['number'], '/') . ' placed: \$[\d,]+\.\d{2}$/')
        ->and($sent[0]['to'])->toBe(['"' . DemoAccount::NAME . '" <' . DemoAccount::EMAIL . '>'])
        ->and((string) $sent[0]['text'])->toContain("{$origin}{$result['path']}")
        ->and((string) $sent[0]['html'])->toContain("src=\"{$origin}/brand/mark-64.png\"")
        ->and((string) $sent[0]['html'])->toContain('style="')
        // Both background children ran, and stopped with serve.
        ->and($served)->toContain('Working mail, webhooks, default, transfers.')
        ->and($served)->toContain('Running the schedule every minute.')
        ->and($exit)->toBe(0);
});
