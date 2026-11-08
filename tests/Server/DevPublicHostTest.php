<?php

declare(strict_types=1);

/**
 * A tunnel's one name in dev (ADR 0190): `serve --dev --public-host` lets in
 * exactly that name beside loopback's, and only for a visitor holding the
 * run's share token -- first in the query, which is taken out before the
 * app reads it, then in a cookie of its own. Every other name is still
 * refused, as DNS rebinding would send it.
 *
 * The dev Caddyfile is run for real, by FrankenPHP, in front of a front
 * controller that says what it was asked. Skipped without FrankenPHP.
 */

namespace Tinvar\Tests\Server\DevPublicHost;

use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\PublicHost;
use Tinvar\Server\ServeCommand;
use Tinvar\Server\ServeException;

const ROOT = __DIR__ . '/../../../..';
const NAME = 'grade-habits-seller-arrange.trycloudflare.com';
const TOKEN = '0123456789abcdef0123456789abcdef';

// ------------------------------------------------------------- the name

it('takes a host name a tunnel serves, normalized, with a fresh token each time', function () {
    $one = PublicHost::of('Dev.Example.com');
    $two = PublicHost::of('dev.example.com');

    expect($one->host)->toBe('dev.example.com')
        ->and($one->token)->toMatch('/^[0-9a-f]{32}\z/')
        ->and($one->token)->not->toBe($two->token)
        ->and(PublicHost::of(NAME, TOKEN)->shareUrl())->toBe('https://' . NAME . '/?tinvar-share=' . TOKEN)
        ->and(PublicHost::of(NAME, TOKEN)->environment())->toBe([
            'TINVAR_DEV_PUBLIC_HOST' => NAME,
            'TINVAR_DEV_SHARE_TOKEN' => TOKEN,
        ]);
});

it('refuses what is not a tunnel\'s host name', function (string $given) {
    expect(fn () => PublicHost::of($given))->toThrow(ServeException::class, '--public-host');
})->with([
    'a URL' => 'https://dev.example.com',
    'a port' => 'dev.example.com:443',
    'a path' => 'dev.example.com/x',
    'an address' => '203.0.113.7',
    'an IPv6 address' => '[::1]',
    'loopback\'s name' => 'localhost',
    'a name under .localhost' => 'app.localhost',
    'a single label' => 'devbox',
    'the dev Caddyfile\'s stand-in' => 'public-host.tinvar.invalid',
    'nothing' => '',
    'a wildcard' => '*.trycloudflare.com',
    'two names' => 'a.example.com b.example.com',
]);

// -------------------------------------------------------------- serving

function devApp(): string
{
    $app = sys_get_temp_dir() . '/tinvar-public-host-' . bin2hex(random_bytes(4));
    mkdir("{$app}/public", 0755, true);
    file_put_contents("{$app}/public/index.php", '<?php echo json_encode(["host" => $_SERVER["HTTP_HOST"] ?? null, "query" => $_SERVER["QUERY_STRING"] ?? null, "get" => $_GET, "uri" => $_SERVER["REQUEST_URI"] ?? null]);');
    register_shutdown_function(static function () use ($app): void {
        exec('rm -rf ' . escapeshellarg($app));
    });

    return (string) realpath($app);
}

it('plans --public-host for dev alone', function () {
    $app = devApp();
    file_put_contents("{$app}/Caddyfile", '');
    $command = new ServeCommand(projectRoot: ROOT);

    try {
        $plan = $command->plan(['--dev', "--app={$app}", '--public-host=' . NAME]);
    } catch (ServeException $e) {
        // No FrankenPHP or renderer here: what is planned of the name is
        // decided before either is looked for, and refused the same way.
        $this->markTestSkipped($e->getMessage());
    }

    expect($plan['publicHost']?->host)->toBe(NAME)
        ->and($command->plan(['--dev', "--app={$app}"])['publicHost'])->toBeNull()
        ->and(fn () => $command->plan(["--app={$app}", '--public-host=' . NAME]))
        ->toThrow(ServeException::class, '--public-host is for --dev')
        ->and(fn () => $command->plan(['--dev', "--app={$app}", '--public-host=localhost']))
        ->toThrow(ServeException::class, 'localhost is not one');
});

/**
 * FrankenPHP on the dev Caddyfile, told the name and token as serve tells
 * it, or neither.
 *
 * @return array{int, resource}
 */
function started(string $app, ?PublicHost $public): array
{
    try {
        $frankenphp = FrankenPhp::locate(ROOT);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
    $port = new PortFinder()->find('127.0.0.1');
    $env = [
        ...array_diff_key(array_filter(getenv(), \is_string(...)), array_flip([PublicHost::HOST_ENV, PublicHost::TOKEN_ENV])),
        'TINVAR_HOST' => '127.0.0.1',
        'TINVAR_PORT' => (string) $port,
        'TINVAR_APP_ROOT' => $app,
        // Nothing listens there: no request here reaches the dev server.
        'TINVAR_VITE_PORT' => '9',
        'TINVAR_LIVE_SUBSCRIBER_KEY' => str_repeat('s', 32),
        'TINVAR_LIVE_PUBLISHER_KEY' => str_repeat('p', 32),
        ...($public?->environment() ?? []),
    ];
    $process = proc_open(
        [$frankenphp, 'run', '--config', ROOT . '/packages/framework/resources/Caddyfile.dev', '--adapter', 'caddyfile'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$app}/frankenphp.log", 'w']],
        $pipes,
        $app,
        $env,
    );
    \assert(\is_resource($process));
    $deadline = microtime(true) + 20;
    while (!PortFinder::answers('127.0.0.1', $port) && microtime(true) < $deadline) {
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(50_000);
    }
    expect(PortFinder::answers('127.0.0.1', $port))->toBeTrue('FrankenPHP started: ' . @file_get_contents("{$app}/frankenphp.log"));

    return [$port, $process];
}

/** @param resource $process */
function stop($process): void
{
    proc_terminate($process, SIGTERM);
    $deadline = microtime(true) + 10;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, SIGKILL);
    }
    proc_close($process);
}

/**
 * @param list<string> $headers
 * @return array{int, list<string>, string, string|null} status, Set-Cookie values, body, Location
 */
function fetch(int $port, string $host, string $path = '/', array $headers = [], string $method = 'GET'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", ["Host: {$host}", ...$headers]),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    /** @var list<string> $lines */
    $lines = $http_response_header ?? [];
    preg_match('#^HTTP/\S+ (\d{3})#', $lines[0] ?? '', $m);
    $cookies = [];
    $location = null;
    foreach ($lines as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $cookies[] = trim(substr($line, \strlen('set-cookie:')));
        }
        if (stripos($line, 'location:') === 0) {
            $location = trim(substr($line, \strlen('location:')));
        }
    }

    return [(int) ($m[1] ?? 0), $cookies, $body, $location];
}

it('answers the tunnel\'s name only with the share token, and every other name as before', function () {
    $app = devApp();
    [$port, $process] = started($app, PublicHost::of(NAME, TOKEN));

    try {
        // Loopback's names, with no token, as ever.
        expect(fetch($port, "127.0.0.1:{$port}")[0])->toBe(200)
            ->and(fetch($port, 'localhost')[0])->toBe(200)
            // Any other name is a rebinding page's, as ever.
            ->and(fetch($port, 'evil.example')[0])->toBe(421)
            ->and(fetch($port, 'other.trycloudflare.com')[0])->toBe(421);

        // The tunnel's name, without the token: nothing of the app, nor of
        // the dev server's modules.
        expect(fetch($port, NAME)[0])->toBe(403)
            ->and(fetch($port, NAME, '/_vite/@vite/client')[0])->toBe(403)
            ->and(fetch($port, NAME, '/?tinvar-share=' . str_repeat('0', 32))[0])->toBe(403)
            ->and(fetch($port, NAME, '/', ['Cookie: __Host-tinvar-share=' . str_repeat('0', 32)])[0])->toBe(403)
            // A cookie whose name merely ends the same way is not the token's.
            ->and(fetch($port, NAME, '/', ['Cookie: x__Host-tinvar-share=' . TOKEN])[0])->toBe(403)
            ->and(fetch($port, NAME, '/', ['Cookie: __Host-tinvar-share=' . TOKEN . 'ff'])[0])->toBe(403);

        // The name as a client may spell it, and the hub's stream: the same gate.
        expect(fetch($port, strtoupper(NAME))[0])->toBe(403)
            ->and(fetch($port, NAME . ':443')[0])->toBe(403)
            ->and(fetch($port, NAME, '/.well-known/mercure?topic=x')[0])->toBe(403);

        // A page with it in the query: the cookie set, and sent on to its
        // address without the token, the rest of the query kept -- so the
        // token is in no address bar, history or request line after.
        [$status, $cookies, , $location] = fetch($port, NAME, '/notes?page=2&tinvar-share=' . TOKEN);
        expect($status)->toBe(303)
            ->and($cookies)->toContain('__Host-tinvar-share=' . TOKEN . '; Path=/; Secure; HttpOnly; SameSite=Lax')
            ->and($location)->toBe('/notes?page=2')
            ->and(fetch($port, NAME, '/?tinvar-share=' . TOKEN)[3])->toBe('/');

        // Anything else -- a webhook's POST -- goes on, without the token in
        // the query the app reads.
        [$status, , $body] = fetch($port, NAME, '/hook?page=2&tinvar-share=' . TOKEN, ['Content-Type: application/json'], 'POST');
        expect($status)->toBe(200)
            ->and(json_decode($body, true))->toMatchArray(['host' => NAME, 'query' => 'page=2', 'get' => ['page' => '2']]);

        // With the cookie, among others: every request after.
        expect(fetch($port, NAME, '/', ['Cookie: a=b; __Host-tinvar-share=' . TOKEN . '; c=d'])[0])->toBe(200)
            ->and(fetch($port, NAME, '/', ['Cookie: __Host-tinvar-share=' . TOKEN])[0])->toBe(200);
    } finally {
        stop($process);
    }
});

it('refuses every name but loopback\'s where serve names no tunnel', function () {
    $app = devApp();
    [$port, $process] = started($app, null);

    try {
        expect(fetch($port, 'localhost')[0])->toBe(200)
            ->and(fetch($port, NAME)[0])->toBe(421)
            ->and(fetch($port, NAME, '/?tinvar-share=none')[0])->toBe(421)
            // The stand-in for no name, with the stand-in for no token, as a
            // request built by hand may carry them: never let in.
            ->and(fetch($port, 'public-host.tinvar.invalid')[0])->toBe(421)
            ->and(fetch($port, 'public-host.tinvar.invalid', '/?tinvar-share=none')[0])->toBe(421)
            ->and(fetch($port, 'public-host.tinvar.invalid', '/', ['Cookie: __Host-tinvar-share=none'])[0])->toBe(421);
    } finally {
        stop($process);
    }
});
