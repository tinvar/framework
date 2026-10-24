<?php

declare(strict_types=1);

/**
 * The shop's catalog built statically, for real: rendered through the shop's
 * Kernel and the renderer as a visitor with no session, written to a file,
 * and served before PHP boots -- by the front controller and by Caddy -- to
 * a visitor the shop has not met, while everyone else still gets PHP.
 *
 * "Stored output (static, revalidated) never contains a CSRF token", and
 * fragment tokens "never [come] from the build": whatever is a visitor's own
 * reaches them from PHP afterwards. So the file is held to containing
 * nothing of anyone's, and to being the same file however often it is built.
 */

namespace Tinvar\Tests\Integration\ShopStaticPage;

use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\StaticBuilder;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;
use Tinvar\Support\Signer;

const KEY = 'shop-static-page-test-key-of-32-bytes';

$root = \dirname(__DIR__, 4);

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A directory removed when the test process ends. */
function scratch(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-shop-static-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return $directory;
}

/**
 * The shop's static pages built into $out, as `tinvar build:static` builds
 * them: the manifest from the build, then every #[StaticPage] rendered.
 *
 * @return array{list<string>, FragmentManifest}
 */
function build(string $out): array
{
    $renderer = Renderer::start('msgpack', 'static-build');
    try {
        $routes = new \Tinvar\Routing\RouteRegistry();
        $routes->registerAll(Kernel::ROUTES);
        $manifest = FragmentManifestProducer::produce($routes, Shop::root() . '/dist/rsc/deferred.json');
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), manifest: $manifest);

        return [new StaticBuilder($kernel->routes, $kernel->renderStatic(...))->build($out, $manifest), $manifest];
    } finally {
        $renderer->stop();
    }
}

/**
 * The demo customer signed in on this Kernel.
 *
 * @return array<string, string> their cookies
 */
function signIn(Kernel $kernel): array
{
    $cookies = [];
    $keep = static function (Response $response) use (&$cookies): Response {
        foreach ($response->cookies() as $name => $line) {
            $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    };
    $keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'])));
    $csrf = (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', cookies: $cookies));

    $signIn = $keep($kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $csrf],
            cookies: [...$cookies, '__Host-XSRF-TOKEN' => $csrf],
        ),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/'], JSON_THROW_ON_ERROR),
    ));
    expect($signIn->status)->toBe(303, $signIn->body());

    return $cookies;
}

/**
 * @return array{int, array<string, string>, string} status, headers by lowercase
 *         name -- a repeated one joined with commas -- and body
 */
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
            $name = strtolower($name);
            $parsed[$name] = isset($parsed[$name]) ? "{$parsed[$name]}, " . trim($value) : trim($value);
        }
    }

    return [$status, $parsed, $body];
}

/**
 * The shop behind php -S, serving the built pages in $static: a renderer, a
 * seeded database and the front controller.
 *
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, string $static, \Closure $drive): void
{
    $renderer = Renderer::start('msgpack', 'static-served');
    $port = 19_000 + random_int(0, 999);
    $keyDir = scratch();
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'TINVAR_SOCKET' => $renderer->socketPath,
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            'SHOP_DATABASE' => Shop::database(),
            'SHOP_STATIC_DIR' => $static,
        ],
    );

    try {
        set_error_handler(static fn (): bool => true);
        try {
            $deadline = microtime(true) + 10;
            while (fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        expect(proc_get_status($server)['running'])->toBeTrue("php -S did not start on port {$port}");

        $drive("http://127.0.0.1:{$port}");
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
    }
}

describe('the built catalog', function () {
    it('is the catalog as a visitor with no session sees it, with nothing of anyone in it', function () {
        $out = scratch() . '/static';
        [$built] = build($out);
        $html = (string) file_get_contents("{$out}/index.html");

        preg_match('#<script type="application/json" id="tinvar-page">(.*?)</script>#s', $html, $metadata);
        $metadata = json_decode($metadata[1] ?? 'null', true);
        preg_match('#<meta http-equiv="Content-Security-Policy" content="script-src \'nonce-([^\']+)\'#', $html, $policy);
        preg_match_all('#<script\b[^>]*\bnonce="([^"]+)"#', $html, $scripts);

        expect($built)->toBe(['/'])
            ->and($html)->toContain('<title>Catalog · Pellwick Supply</title>')
            ->and(substr_count($html, 'data-product-name=""'))->toBe(24)
            // Signed out: the header offers sign-in, as it does to anyone.
            ->and($html)->toContain('/sign-in')
            // The page metadata: the build's version, and no route token or
            // history key, which are a session's.
            ->and($metadata)->toBe(['url' => '/', 'version' => 'dev', 'routeToken' => null, 'view' => 'shop/catalog'])
            // No token of any kind, no CSRF value, no cookie's name.
            ->and(preg_match('/v1\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/', $html))->toBe(0)
            ->and($html)->not->toContain('__Host-')
            ->and($html)->toContain('csrfToken\":null')
            // Its policy is in the file, which no server adds a header to,
            // and every script carries its nonce.
            ->and($policy[1] ?? null)->not->toBeNull()
            ->and(array_unique($scripts[1]))->toBe([$policy[1] ?? '']);
    });

    // Built by a worker that has just served a signed-in customer, it still
    // knows no one: the build's request carries no cookie, so the layout's
    // loader finds no customer, and the session's tokens have nothing to be
    // minted for.
    it('shows no customer, even built on a server where one has just signed in', function () {
        $out = scratch() . '/static';
        $renderer = Renderer::start('msgpack', 'static-signed-in');
        try {
            $routes = new \Tinvar\Routing\RouteRegistry();
            $routes->registerAll(Kernel::ROUTES);
            $manifest = FragmentManifestProducer::produce($routes, Shop::root() . '/dist/rsc/deferred.json');
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), manifest: $manifest);

            $cookies = signIn($kernel);
            $signedIn = $kernel->handle(new Request('GET', '/', ['accept' => 'text/html'], cookies: $cookies));
            new StaticBuilder($kernel->routes, $kernel->renderStatic(...))->build($out, $manifest);
        } finally {
            $renderer->stop();
        }
        $html = (string) file_get_contents("{$out}/index.html");

        // The customer, as the page rendered for them shows them.
        expect($signedIn->body())->toContain(DemoAccount::EMAIL)
            ->and($html)->not->toContain(DemoAccount::EMAIL)
            ->and($html)->not->toContain(DemoAccount::NAME)
            ->and($html)->toContain('/sign-in');
    });

    // Rendered as nobody, through the page's whole path: a page its checks
    // keep from a visitor with no session -- here the orders, which send one
    // to sign in -- is refused, never rendered for the build.
    it('refuses a page a visitor with no session would not be shown', function () {
        $renderer = Renderer::start('msgpack', 'static-refused');
        try {
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());

            expect(fn () => $kernel->renderStatic(new Request('GET', '/orders', ['accept' => 'text/html']), static fn (): string => ''))
                ->toThrow(\RuntimeException::class, 'failed with 302');
        } finally {
            $renderer->stop();
        }
    });

    // The catalog has no <Deferred> boundary, so nothing for /_bootstrap.
    it('declares no boundaries, so the shop mounts no bootstrap endpoint for it', function () {
        [, $manifest] = build(scratch() . '/static');

        expect($manifest->hasAny())->toBeFalse()
            ->and($manifest->routes())->toBe(['orders.show']);
    });
});

describe('serving it', function () use ($root) {
    it('sends the file to a visitor with no session, and PHP to everyone else', function () use ($root) {
        $static = scratch() . '/static';
        build($static);
        $file = (string) file_get_contents("{$static}/index.html");

        served($root, $static, function (string $origin) use (&$results): void {
            $results = [
                'first' => fetch("{$origin}/", ['Accept: text/html']),
                'session' => fetch("{$origin}/", ['Accept: text/html', 'Cookie: ' . Kernel::SESSION_COOKIE . '=abc']),
                'navigation' => fetch("{$origin}/", ['RSC: 1', 'X-Version: dev']),
                'query' => fetch("{$origin}/?sort=name", ['Accept: text/html']),
            ];
        });

        [$status, $headers, $body] = $results['first'];
        expect($status)->toBe(200)
            ->and($body)->toBe($file)
            ->and($headers)->not->toHaveKey('set-cookie')
            ->and($headers)->not->toHaveKey('content-security-policy')
            ->and($headers['cache-control'] ?? null)->toBe('no-cache')
            ->and($headers['vary'] ?? null)->toBe('Cookie, RSC');

        // A visitor with a cookie -- here one this server would refuse, so a
        // new session is issued -- is answered by PHP, with its own policy.
        [$status, $headers, $body] = $results['session'];
        expect($status)->toBe(200)
            ->and($headers)->toHaveKey('set-cookie')
            ->and($headers['content-security-policy'] ?? '')->toStartWith("script-src 'nonce-")
            ->and($body)->not->toBe($file);

        expect($results['navigation'][1]['content-type'] ?? '')->toStartWith('text/x-component')
            ->and($results['query'][2])->not->toBe($file)
            ->and($results['query'][1])->toHaveKey('content-security-policy');
    });

    // The page hydrates under the policy in the file, navigates as a client
    // visit -- which PHP answers, issuing the visitor their session -- and
    // from then on the catalog comes from PHP.
    it('hydrates and navigates in Chromium, and hands the visitor to PHP from the first request it sees', function () use ($root) {
        if (!is_file("{$root}/node_modules/playwright/package.json")) {
            $this->markTestSkipped('Playwright is not installed.');
        }
        $static = scratch() . '/static';
        build($static);

        $output = '';
        served($root, $static, function (string $origin) use ($root, &$output): void {
            $process = proc_open(
                ['node', "{$root}/examples/shop/e2e/static.mjs", $origin],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $root,
                ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            );
            $output = (string) stream_get_contents($pipes[1]);
            proc_close($process);
        });

        $result = json_decode($output, true);
        expect($result)->toBeArray("the browser script failed:\n{$output}");

        expect($result['errors'])->toBe([])
            ->and($result['violations'])->toBe([])
            ->and($result['first']['setCookie'])->toBeFalse()
            ->and($result['first']['headerPolicy'])->toBeNull()
            ->and($result['first']['metaPolicy'])->toContain("'strict-dynamic'")
            ->and($result['first']['cookies'])->toBe([])
            ->and($result['first']['title'])->toBe('Catalog · Pellwick Supply')
            ->and($result['visited']['heading'])->toBe($result['visited']['name'])
            ->and($result['visited']['cookies'])->toContain(Kernel::SESSION_COOKIE)
            ->and($result['visited']['cookies'])->toContain('__Host-XSRF-TOKEN')
            ->and($result['second']['headerPolicy'])->toStartWith("script-src 'nonce-")
            ->and($result['second']['metaPolicy'])->toBeNull('from PHP, not the file');
    });

    // The Caddyfile's rule, under FrankenPHP: the same answer, with no PHP.
    it('is served by Caddy under tinvar serve, only when SHOP_STATIC_DIR names it', function () use ($root) {
        try {
            FrankenPhp::locate($root);
        } catch (ServeException) {
            $this->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
        }
        $static = scratch() . '/static';
        build($static);
        $file = (string) file_get_contents("{$static}/index.html");
        $keyDir = scratch();

        $process = proc_open(
            [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=1'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
            [
                ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
                'SHOP_DATABASE' => Shop::database(),
                'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
                'SHOP_STATIC_DIR' => $static,
            ],
        );
        stream_set_blocking($pipes[1], false);

        try {
            $printed = '';
            $deadline = microtime(true) + 60;
            while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $printed, $m) !== 1) {
                $printed .= (string) stream_get_contents($pipes[1]);
                usleep(50_000);
            }
            expect($m[1] ?? null)->not->toBeNull("tinvar serve printed no URL:\n{$printed}");

            $first = fetch("{$m[1]}/", ['Accept: text/html']);
            $session = fetch("{$m[1]}/", ['Accept: text/html', 'Cookie: ' . Kernel::SESSION_COOKIE . '=abc']);
            $navigation = fetch("{$m[1]}/", ['RSC: 1', 'X-Version: dev']);
            $built = fetch("{$m[1]}/index.html", ['Accept: text/html']);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
            }
            $deadline = microtime(true) + 20;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            fclose($pipes[1]);
            proc_close($process);
        }

        expect($first[0])->toBe(200)
            ->and($first[2])->toBe($file)
            ->and($first[1])->not->toHaveKey('set-cookie')
            ->and($first[1]['cache-control'] ?? null)->toBe('no-cache')
            ->and($first[1]['vary'] ?? '')->toContain('Cookie, RSC')
            ->and($session[1])->toHaveKey('set-cookie')
            ->and($session[1]['content-security-policy'] ?? '')->toStartWith("script-src 'nonce-")
            ->and($navigation[1]['content-type'] ?? '')->toStartWith('text/x-component')
            // Only by the page's path: the file itself is no page.
            ->and($built[2])->not->toBe($file);
    });
});

// What the client does on a static page's first boundary load, against the
// shop: the visitor has no session and no CSRF cookie, so the first POST is
// refused with 419 -- and given both cookies -- and the second gets the
// tokens, bound to the session it was just given.
it('hands a first-time visitor their tokens on the second bootstrap request', function () {
    $manifest = new FragmentManifest()
        ->declareRoute('orders.show', ['ShippingUpdates'])
        ->declare('orders.show', '0', 'ShippingUpdates', ['order' => 'o-1'], ['order' => 'o-1']);
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-none.sock', key: KEY, database: Shop::database(), manifest: $manifest);
    $body = json_encode(['route' => 'orders.show', 'params' => ['order' => 'o-1']], JSON_THROW_ON_ERROR);
    $post = static fn (array $cookies, array $headers = []): Response => $kernel->handle(
        new Request('POST', BootstrapEndpoint::PATH, ['content-type' => 'application/json', 'accept' => 'application/json', ...$headers], cookies: $cookies),
        $body,
    );

    $first = $post([]);
    $cookies = [];
    foreach ($first->cookies() as $name => $line) {
        $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
    }
    $second = $post($cookies, ['x-xsrf-token' => $cookies['__Host-XSRF-TOKEN'] ?? '']);
    $token = json_decode($second->body(), true)['tokens']['0'] ?? '';
    $signer = new Signer(KEY, FragmentToken::PURPOSE);

    expect($first->status)->toBe(419)
        ->and(array_keys($cookies))->toEqualCanonicalizing([Kernel::SESSION_COOKIE, '__Host-XSRF-TOKEN'])
        ->and($second->status)->toBe(200, $second->body())
        ->and(FragmentToken::decode($token, $signer)->belongsTo(
            $signer,
            (string) Kernel::sessionFrom($cookies[Kernel::SESSION_COOKIE], KEY),
        ))->toBeTrue();
});

describe('tinvar build:static', function () use ($root) {
    it('builds the pages and writes the manifest where it is told', function () use ($root) {
        $out = scratch();
        $keyDir = scratch();
        $process = proc_open(
            [PHP_BINARY, "{$root}/bin/tinvar", 'build:static', "--out={$out}/static", "--manifest={$out}/fragments.json"],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            [
                ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
                'SHOP_DATABASE' => Shop::database(),
                'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            ],
        );
        $output = (string) stream_get_contents($pipes[1]);
        $status = proc_close($process);

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('built   /')
            ->and(is_file("{$out}/static/index.html"))->toBeTrue()
            ->and(FragmentManifest::fromFile("{$out}/fragments.json")->routes())->toBe(['orders.show']);
    });

    it('refuses an argument it does not know', function () use ($root) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/tinvar") . ' build:static --into=x 2>&1', $output, $status);

        expect($status)->toBe(64)
            ->and(implode("\n", $output))->toContain('Usage: tinvar build:static');
    });
});
