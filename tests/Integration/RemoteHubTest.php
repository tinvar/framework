<?php

declare(strict_types=1);

/**
 * A Mercure hub of its own (ADR 0048): the framework's
 * resources/Caddyfile.hub, run by FrankenPHP as a process apart from any
 * app server, published to over HTTP by Mercure::remote() -- the hub every
 * app server and worker shares once there is more than one.
 *
 * Against the hub itself, what MercureTest checks against a recorded POST:
 * an update goes out under a publisher token for its one topic, reaches the
 * subscriber whose access token names that topic and no one else, and a
 * token for one topic -- or one signed with the subscriber key -- publishes
 * nothing anywhere else.
 *
 * Skipped without FrankenPHP.
 */

namespace Tinvar\Tests\Integration\RemoteHub;

use Tinvar\Http\Response;
use Tinvar\Live\HubSettings;
use Tinvar\Live\Mercure;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

const KEY = 'remote-hub-test-application-key-0123456789';
const TOPIC = 'tinvar:AAAAAAAAAAAAAAAAAAAAAA';
const OTHER = 'tinvar:BBBBBBBBBBBBBBBBBBBBBB';

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
});

/**
 * The hub on a port of its own for one test, given its URL and settings.
 *
 * @param \Closure(string, HubSettings): void $drive
 */
function hub(string $root, \Closure $drive): void
{
    $port = new PortFinder()->find('127.0.0.1');
    $url = "http://127.0.0.1:{$port}" . Mercure::PATH;
    $derived = HubSettings::derive(KEY);
    $settings = new HubSettings($derived->subscriberKey, $derived->publisherKey, $derived->audience, $url);
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-hub-log-');

    $process = proc_open(
        [FrankenPhp::locate($root), 'run', '--config', "{$root}/packages/framework/resources/Caddyfile.hub", '--adapter', 'caddyfile'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        sys_get_temp_dir(),
        [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            ...$settings->environment(),
            'TINVAR_LIVE_HUB_PORT' => (string) $port,
        ],
    );

    try {
        $deadline = microtime(true) + 20;
        while (!PortFinder::answers('127.0.0.1', $port) && microtime(true) < $deadline && proc_get_status($process)['running']) {
            usleep(50_000);
        }
        expect(PortFinder::answers('127.0.0.1', $port))->toBeTrue('The hub never answered: ' . (string) file_get_contents($log));

        $drive($url, $settings);
    } finally {
        proc_terminate($process);
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        @unlink($log);
    }
}

/**
 * A subscription opened with $cookie, if any, for $topics: its socket, once
 * the hub has answered its headers, and the status it answered with.
 *
 * @param list<string> $topics
 * @return array{resource, int}
 */
function subscribe(string $url, array $topics, ?string $cookie): array
{
    $parts = parse_url($url);
    \assert(\is_array($parts));
    $socket = stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $error, 5);
    \assert(\is_resource($socket), $error);

    $query = implode('&', array_map(static fn (string $t): string => 'match=' . rawurlencode($t), $topics));
    fwrite($socket, "GET {$parts['path']}?{$query} HTTP/1.1\r\nHost: {$parts['host']}\r\nAccept: text/event-stream\r\n"
        . ($cookie === null ? '' : 'Cookie: ' . Mercure::COOKIE . "={$cookie}\r\n")
        . "\r\n");
    stream_set_timeout($socket, 5);

    $status = (string) fgets($socket);
    // The headers, read off so what follows is the stream.
    while (($line = fgets($socket)) !== false && rtrim($line) !== '') {
    }

    return [$socket, (int) (explode(' ', $status)[1] ?? 0)];
}

/**
 * What arrives on a subscription within $seconds.
 *
 * @param resource $socket
 */
function heard($socket, float $seconds): string
{
    stream_set_blocking($socket, false);
    $read = '';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $read .= (string) fread($socket, 8192);
        usleep(20_000);
    }

    return $read;
}

/** A POST to the hub with $token, as Mercure::remote() sends one; its status. */
function publishWith(string $url, string $token, string $topic, string $data): int
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "authorization: Bearer {$token}\r\ncontent-type: application/x-www-form-urlencoded",
        'content' => http_build_query(['topic' => $topic, 'data' => $data, 'private' => 'on']),
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $body = file_get_contents($url, false, $context);
    \assert($body !== false);
    /** @var list<string> $http_response_header */
    preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m);

    return (int) ($m[1] ?? 0);
}

/** The access token a grant sets, read off its cookie. */
function cookieOf(Response $response): string
{
    $line = $response->cookies()[Mercure::COOKIE];

    return explode(';', substr($line, \strlen(Mercure::COOKIE) + 1))[0];
}

it('takes an update over HTTP and hands it only to the subscriber its topic was granted to', function () use ($root) {
    hub($root, function (string $url, HubSettings $settings): void {
        $hub = $settings->hub();
        \assert($hub !== null);
        $access = cookieOf($hub->grant(Response::make(200), [TOPIC], time() + 60, 'session-hash'));

        // Asking for both topics, granted one.
        [$granted, $status] = subscribe($url, [TOPIC, OTHER], $access);
        [$anonymous, $refused] = subscribe($url, [TOPIC], null);
        expect($status)->toBe(200)->and($refused)->toBe(401);

        $hub->publish(TOPIC, '{"topic":"' . TOPIC . '","tag":"orders:customer:42"}');
        $hub->publish(OTHER, '{"topic":"' . OTHER . '","tag":"orders:customer:7"}');

        $stream = heard($granted, 1.5);
        expect($stream)->toContain('orders:customer:42')
            ->and($stream)->not->toContain('orders:customer:7');

        fclose($granted);
        fclose($anonymous);
    });
});

it('refuses an update from a token for another topic, or one signed with the subscriber key', function () use ($root) {
    hub($root, function (string $url, HubSettings $settings): void {
        $hub = $settings->hub();
        \assert($hub !== null);
        [$listener] = subscribe($url, [OTHER], cookieOf($hub->grant(Response::make(200), [OTHER], time() + 60, 'session-hash')));

        // What Mercure::remote() signs for TOPIC, sent with an update for OTHER.
        $forTopic = Mercure::accessToken($settings->publisherKey, $settings->audience, 'publisher', ['publish'], [TOPIC], time() + 60);
        // A subscriber's grant, which the client never sees, used to publish.
        $subscriberSigned = Mercure::accessToken($settings->subscriberKey, $settings->audience, 'publisher', ['publish'], [OTHER], time() + 60);

        expect(publishWith($url, $forTopic, OTHER, '{"tag":"forged-one"}'))->toBeGreaterThanOrEqual(400)
            ->and(publishWith($url, $subscriberSigned, OTHER, '{"tag":"forged-two"}'))->toBeGreaterThanOrEqual(400)
            // And the real thing gets through, so the refusals above are the hub's judgement of the tokens.
            ->and(publishWith($url, Mercure::accessToken($settings->publisherKey, $settings->audience, 'publisher', ['publish'], [OTHER], time() + 60), OTHER, '{"tag":"genuine"}'))->toBe(200);

        $stream = heard($listener, 1.5);
        expect($stream)->toContain('genuine')
            ->and($stream)->not->toContain('forged');

        fclose($listener);
    });
});
