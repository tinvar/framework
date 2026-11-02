<?php

declare(strict_types=1);

/**
 * The shop's live updates through a hub of its own (ADR 0048): the hub
 * run apart from the shop, by the framework's resources/Caddyfile.hub, and
 * the shop under `tinvar serve` told of it by TINVAR_LIVE_HUB_URL -- what
 * more than one app server needs, since FrankenPHP's built-in hub reaches
 * only the subscribers of the server that published.
 *
 * The same browser script as ShopLiveTest: a customer places an order in
 * one tab, and their other tab and staff's list update without a reload.
 * Here every update goes out over HTTP to the hub apart, which a listener
 * of the test's own, connected to the hub directly, hears too; and every
 * subscription goes in at the shop's /.well-known/mercure, which passes it
 * on. The shop's own hub is never used: an update published there, or a
 * subscription answered there, would never meet the other.
 *
 * Skipped without FrankenPHP, Playwright or the JavaScript dependencies.
 */

namespace Tinvar\Tests\Integration\ShopRemoteHub;

use Shop\Http\Actions\Orders\OrderActions;
use Shop\Support\DemoAccount;
use Shop\Support\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Http\Response;
use Tinvar\Live\HubSettings;
use Tinvar\Live\Mercure;
use Tinvar\Live\Topics;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;
use Tinvar\Support\Signer;

$root = \dirname(__DIR__, 4);

const KEY = 'shop-remote-hub-test-key-of-32-bytes!!';

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

/** @param resource $process */
function stop($process, int $signal = SIGTERM): void
{
    $status = proc_get_status($process);
    if ($status['running']) {
        posix_kill($status['pid'], $signal);
    }
    $deadline = microtime(true) + 20;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
}

/**
 * A subscription opened at the hub itself with an access token for $topic.
 *
 * @return resource
 */
function listen(string $hubUrl, HubSettings $settings, string $topic)
{
    $grant = Mercure::remote($hubUrl, $settings->publisherKey, $settings->subscriberKey, $settings->audience)
        ->grant(Response::make(200), [$topic], time() + 120, 'the-test');
    $cookie = explode(';', substr($grant->cookies()[Mercure::COOKIE], \strlen(Mercure::COOKIE) + 1))[0];

    $parts = parse_url($hubUrl);
    \assert(\is_array($parts));
    $socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $error, 5);
    \assert(\is_resource($socket), $error);
    fwrite($socket, "GET {$parts['path']}?match=" . rawurlencode($topic) . " HTTP/1.1\r\nHost: {$parts['host']}\r\n"
        . 'Accept: text/event-stream' . "\r\nCookie: " . Mercure::COOKIE . "={$cookie}\r\n\r\n");
    stream_set_blocking($socket, false);

    return $socket;
}

it('updates another tab and staff\'s list through a hub apart from the shop', function () use ($root) {
    $hubPort = new PortFinder()->find('127.0.0.1');
    $hubUrl = "http://127.0.0.1:{$hubPort}" . Mercure::PATH;
    $settings = HubSettings::derive(KEY);
    $hubLog = (string) tempnam(sys_get_temp_dir(), 'tinvar-remote-hub-');
    $serveLog = (string) tempnam(sys_get_temp_dir(), 'tinvar-remote-serve-');

    // The hub, with the keys the shop derives from its app key: given to the
    // hub's process and the shop's, never to the renderer.
    $hub = proc_open(
        [FrankenPhp::locate($root), 'run', '--config', "{$root}/packages/framework/resources/Caddyfile.hub", '--adapter', 'caddyfile'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $hubLog, 'w'], 2 => ['file', $hubLog, 'a']],
        $hubPipes,
        sys_get_temp_dir(),
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), ...$settings->environment(), 'TINVAR_LIVE_HUB_PORT' => (string) $hubPort],
    );

    $serve = null;
    $output = '';
    $heard = '';
    try {
        $deadline = microtime(true) + 20;
        while (!PortFinder::answers('127.0.0.1', $hubPort) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect(PortFinder::answers('127.0.0.1', $hubPort))->toBeTrue('The hub never answered: ' . (string) file_get_contents($hubLog));

        $serve = proc_open(
            [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $serveLog, 'w']],
            $pipes,
            $root,
            [
                ...array_diff_key(getenv(), array_flip([
                    'TINVAR_APP_KEY_FILE', HubSettings::SUBSCRIBER_ENV, HubSettings::PUBLISHER_ENV, HubSettings::AUDIENCE_ENV,
                ])),
                'TINVAR_APP_KEY' => KEY,
                HubSettings::HUB_URL_ENV => $hubUrl,
                // Its own database -- placing an order writes to it -- by a
                // customer count no other test asks for.
                'SHOP_DATABASE' => Shop::database(customers: 27),
            ],
        );
        stream_set_blocking($pipes[1], false);

        $printed = '';
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $printed, $m) !== 1) {
            $printed .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($serve)['running']) {
                break;
            }
            usleep(50_000);
        }
        expect($m[1] ?? null)->not->toBeNull(
            "tinvar serve printed no URL:\n{$printed}\n" . implode('', \array_slice(file($serveLog) ?: [], -20)),
        );

        // Listening at the hub itself for every customer's orders, which
        // placing an order invalidates: the shop's update reaches the hub
        // apart, or this hears nothing.
        $listener = listen($hubUrl, $settings, new Topics(new Signer(KEY, Topics::PURPOSE))->for('', OrderActions::ORDERS_TAG));

        $output = runWithTimeout([
            'node',
            "{$root}/examples/shop/e2e/live.mjs",
            $m[1],
            DemoAccount::EMAIL,
            DemoAccount::PASSWORD,
            StaffAccount::EMAIL,
            StaffAccount::PASSWORD,
        ], $root, 90);

        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && !str_contains($heard, '"tag":"orders"')) {
            $heard .= (string) fread($listener, 8192);
            usleep(20_000);
        }
        fclose($listener);
        fclose($pipes[1]);
    } finally {
        if ($serve !== null) {
            stop($serve);
        }
        stop($hub);
        @unlink($hubLog);
        $tail = implode('', \array_slice(file($serveLog) ?: [], -20));
        @unlink($serveLog);
    }

    $result = json_decode($output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}\n{$tail}");

    expect($result['errors'])->toBe([])
        ->and($result['number'])->toBeString();

    // The customer's other tab, as with the built-in hub: the new order at
    // the top, the page segment that read the tag fetched again in the
    // background, and the subscription made at the shop's own origin.
    $other = $result['other'];
    expect($other['after']['first'])->toBe($result['number'])
        ->and($other['sameDocument'])->toBeTrue()
        ->and($other['documents'])->toBe(0)
        ->and($other['refetches'])->toHaveCount(1)
        // The page's token first, then the bell's inbox channel (ADR 0155),
        // whose subscription may land after the first trade and is traded
        // again beside it: every trade let in, and the stream listening.
        ->and($other['live'][0] ?? null)->toBe(['path' => '/_live/subscribe', 'status' => 200])
        ->and(end($other['live']))->toBe(['path' => '/.well-known/mercure', 'status' => 200])
        ->and(array_unique(array_column($other['live'], 'status')))->toBe([200]);

    // Staff's list of everyone's orders, in another browser.
    expect($result['admin']['after']['first'])->toBe($result['number'])
        ->and($result['admin']['sameDocument'])->toBeTrue()
        ->and($result['admin']['documents'])->toBe(0);

    // And the hub apart is where the update went.
    expect($heard)->toContain('"tag":"orders"');
});
