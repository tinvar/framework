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

use Shop\Support\DemoAccount;
use Shop\Kernel;
use Shop\Http\Queries\Catalog\Availability;
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
 * them: the manifest from the build, then every #[StaticPage] rendered --
 * from $database, and the manifest written to $manifestFile when given.
 *
 * @return array{list<string>, FragmentManifest}
 */
function build(string $out, ?string $database = null, ?string $manifestFile = null): array
{
    $renderer = Renderer::start('msgpack', 'static-build');
    try {
        $routes = new \Tinvar\Routing\RouteRegistry();
        $routes->registerAll(Kernel::classes()->routes);
        $manifest = FragmentManifestProducer::produce($routes, Shop::root() . '/dist/rsc/deferred.json');
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database ?? Shop::database(), manifest: $manifest);
        $built = new StaticBuilder($kernel->routes, $kernel->renderStatic(...))->build($out, $manifest);
        if ($manifestFile !== null) {
            $manifest->write($manifestFile);
        }

        return [$built, $manifest];
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
    $keep($kernel->handle(new Request('GET', '/sign-in', ['host' => 'localhost', 'accept' => 'text/html'])));
    $csrf = (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', cookies: $cookies, headers: ['host' => 'localhost']));

    $signIn = $keep($kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $csrf],
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
 * seeded database -- $database, when given -- the fragment manifest the
 * build wrote, when given, and the front controller.
 *
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, string $static, \Closure $drive, ?string $database = null, ?string $manifest = null): void
{
    $renderer = Renderer::start('msgpack', 'static-served');
    $keyDir = scratch();
    $errorLog = scratch() . '/server.log';
    $server = proc_open(
        // Port 0, the one php -S binds then named in its first line: a port
        // guessed here could be held by anything else on the machine, which
        // the test would then have driven instead (see BrowserNavigationTest).
        [PHP_BINARY, '-S', '127.0.0.1:0', "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errorLog, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'TINVAR_SOCKET' => $renderer->socketPath,
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            'SHOP_DATABASE' => $database ?? Shop::database(),
            'SHOP_STATIC_DIR' => $static,
            ...($manifest === null ? [] : ['SHOP_FRAGMENTS' => $manifest]),
        ],
    );

    try {
        $port = null;
        $deadline = microtime(true) + 10;
        while ($port === null && proc_get_status($server)['running'] && microtime(true) < $deadline) {
            if (preg_match('#Development Server \(http://127\.0\.0\.1:(\d+)\) started#', (string) file_get_contents($errorLog), $started) === 1) {
                $port = (int) $started[1];
            } else {
                usleep(50_000);
            }
        }
        expect($port)->not->toBeNull('php -S did not start: ' . file_get_contents($errorLog));

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
            ->and($html)->toContain('">Catalog · Pellwick Supply</title>')
            ->and(substr_count($html, 'data-product-name=""'))->toBe(24)
            // Signed out: the header offers sign-in, as it does to anyone.
            ->and($html)->toContain('/sign-in')
            // The page metadata: the build's version, and no route token or
            // history key, which are a session's.
            // What its reports are filed under, with no token to name it
            // (ADR 0129).
            ->and(array_diff_key($metadata, ['hosts' => true]))->toBe(['url' => '/', 'version' => 'dev', 'routeToken' => null, 'view' => 'shop/catalog', 'route' => 'shop.catalog'])
            // And the hosts route() writes links across with: built for the
            // storefront's host, so its own links stay paths (ADR 0149).
            ->and($metadata['hosts']['current'] ?? null)->toBe(['pattern' => null, 'params' => []])
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
            $routes->registerAll(Kernel::classes()->routes);
            $manifest = FragmentManifestProducer::produce($routes, Shop::root() . '/dist/rsc/deferred.json');
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), manifest: $manifest);

            $cookies = signIn($kernel);
            $signedIn = $kernel->handle(new Request('GET', '/', ['host' => 'localhost', 'accept' => 'text/html'], cookies: $cookies));
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

            expect(fn () => $kernel->renderStatic(new Request('GET', '/orders', ['host' => 'localhost', 'accept' => 'text/html']), static fn (): string => ''))
                ->toThrow(\RuntimeException::class, 'failed with 302');
        } finally {
            $renderer->stop();
        }
    });

    // Each card's availability is a <Deferred> boundary in the built page:
    // a placeholder in the file, and in the manifest the component and props
    // /_bootstrap will mint for -- for the visitor's own session -- under an
    // id drawn from them. Built again from the same rows, the same ids.
    it('declares a boundary for each card\'s availability, which mounts /_bootstrap', function () {
        $out = scratch() . '/static';
        [, $manifest] = build($out);
        $boundaries = $manifest->boundariesFor('shop.catalog');
        $html = (string) file_get_contents("{$out}/index.html");
        preg_match_all('/b1\.[A-Za-z0-9_-]+/', $html, $placeholders);
        $products = array_column(array_column($boundaries, 'props'), 'product');

        expect($manifest->hasAny())->toBeTrue()
            ->and($manifest->routes())->toEqualCanonicalizing(['orders.show', 'shop.catalog'])
            ->and($manifest->componentsFor('shop.catalog'))->toBe(['Availability'])
            ->and($boundaries)->toHaveCount(24)
            ->and(array_unique(array_column($boundaries, 'component')))->toBe(['Availability'])
            ->and(array_unique($products))->toHaveCount(24)
            ->and(array_unique($placeholders[0]))->toHaveCount(24)
            // As of the build, in the file, for a visitor with no script.
            ->and(substr_count($html, 'data-availability="'))->toBe(24)
            ->and(array_column(build(scratch() . '/static')[1]->boundariesFor('shop.catalog'), 'id'))
            ->toBe(array_column($boundaries, 'id'));
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

    // The page hydrates under the policy in the file; its cards' boundaries
    // trade the file's placeholders at /_bootstrap -- refused with a 419
    // first, which hands the visitor their session -- and load the stock as
    // it is now, changed since the build; a click is a client visit PHP
    // answers, and from then on the catalog comes from PHP.
    it('hydrates, loads its boundaries through /_bootstrap, and navigates in Chromium', function () use ($root) {
        if (!is_file("{$root}/node_modules/playwright/package.json")) {
            $this->markTestSkipped('Playwright is not installed.');
        }
        $database = Shop::testDatabase();
        $out = scratch();
        $static = "{$out}/static";
        build($static, $database, "{$out}/fragments.json");
        $built = (string) file_get_contents("{$static}/index.html");

        // Changed after the build: the first card in stock to three left,
        // and the last in stock -- in the second group -- to sold out.
        $pdo = new \PDO("sqlite:{$database}");
        $products = $pdo->query('SELECT id, stock FROM products ORDER BY sku')?->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $inStock = array_values(array_filter($products, static fn (array $p): bool => (int) $p['stock'] > Availability::LOW));
        $first = (string) $inStock[0]['id'];
        $last = (string) $inStock[\count($inStock) - 1]['id'];
        expect(array_search($last, array_column($products, 'id'), true))->toBeGreaterThanOrEqual(12, 'in the second group');
        $pdo->prepare('UPDATE products SET stock = 3 WHERE id = ?')->execute([$first]);
        $pdo->prepare('UPDATE products SET stock = 0 WHERE id = ?')->execute([$last]);
        $fresh = [['product' => $first, 'state' => 'low'], ['product' => $last, 'state' => 'sold_out']];

        $output = '';
        served($root, $static, function (string $origin) use ($root, $fresh, &$output): void {
            $process = proc_open(
                ['node', "{$root}/examples/shop/e2e/static.mjs", $origin, json_encode($fresh, JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $root,
                ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            );
            $output = (string) stream_get_contents($pipes[1]);
            proc_close($process);
        }, $database, "{$out}/fragments.json");

        $result = json_decode($output, true);
        expect($result)->toBeArray("the browser script failed:\n{$output}");

        // The file had the first card in stock: as of the build.
        preg_match('#href="/products/' . preg_quote($first, '#') . '".*?data-availability="([a-z_]+)"#s', $built, $asBuilt);
        $exchanges = $result['boundaries']['exchanges'];
        $bootstraps = array_values(array_filter($exchanges, static fn (array $e): bool => $e['path'] === '/_bootstrap'));
        $fragments = array_values(array_filter($exchanges, static fn (array $e): bool => $e['path'] === '/_fragment/'));

        expect($asBuilt[1] ?? null)->toBe('in_stock')
            ->and($result['boundaries']['shown'])->toBe([
                ['product' => $first, 'state' => 'low', 'text' => '3 left'],
                ['product' => $last, 'state' => 'sold_out', 'text' => 'Sold out'],
            ])
            // A first visit: refused for want of a CSRF cookie, given one and
            // a session, then answered with tokens bound to that session.
            ->and($bootstraps[0] ?? null)->toBe(['method' => 'POST', 'path' => '/_bootstrap', 'tokens' => null, 'status' => 419])
            ->and(array_column($bootstraps, 'status'))->toContain(200)
            // Two groups of twelve, one request each.
            ->and($fragments)->toBe([
                ['method' => 'GET', 'path' => '/_fragment/', 'tokens' => 12, 'status' => 200],
                ['method' => 'GET', 'path' => '/_fragment/', 'tokens' => 12, 'status' => 200],
            ])
            ->and($result['boundaries']['rescued'])->toBe(0)
            ->and($result['boundaries']['cookies'])->toContain(Kernel::SESSION_COOKIE)
            // Chromium logs the first bootstrap's 419 as a failed resource;
            // it is the exchange working, and nothing else may be there.
            ->and(array_values(array_filter(
                $result['errors'],
                static fn (string $error): bool => !str_contains($error, 'status of 419'),
            )))->toBe([])
            ->and($result['violations'])->toBe([])
            ->and($result['first']['setCookie'])->toBeFalse()
            ->and($result['first']['headerPolicy'])->toBeNull()
            ->and($result['first']['metaPolicy'])->toContain("'strict-dynamic'")
            ->and($result['first']['title'])->toBe('Catalog · Pellwick Supply')
            ->and($result['visited']['heading'])->toBe($result['visited']['name'])
            ->and($result['visited']['cookies'])->toContain(Kernel::SESSION_COOKIE)
            ->and($result['visited']['cookies'])->toContain('__Host-XSRF-TOKEN')
            ->and($result['second']['headerPolicy'])->toStartWith("script-src 'nonce-")
            ->and($result['second']['metaPolicy'])->toBeNull('from PHP, not the file');

        // What the page reported (ADR 0129): the document's vitals as the
        // visit left it, filed under the route its metadata names, and the
        // plugin its policy blocked, sent on to /_reports by the page itself
        // -- no token, and the blocked URL with no query.
        $vitals = array_values(array_filter($result['reported'], static fn (array $r): bool => $r['path'] === '/_vitals'));
        $reports = array_values(array_filter($result['reported'], static fn (array $r): bool => $r['path'] === '/_reports'));
        expect($vitals[0]['status'] ?? null)->toBe(204)
            ->and($vitals[0]['body']['route'] ?? null)->toBe('shop.catalog')
            ->and($vitals[0]['body']['navigation'] ?? null)->toBe('document')
            ->and($result['blocked'])->toBe(['object-src http://127.0.0.1:' . parse_url((string) ($reports[0]['body'][0]['body']['documentURL'] ?? ''), PHP_URL_PORT) . '/brand/logo.png?token=s3cret'])
            ->and($reports)->toHaveCount(1)
            ->and($reports[0]['status'])->toBe(204)
            ->and($reports[0]['type'])->toBe('application/reports+json')
            ->and($reports[0]['token'])->toBeFalse()
            ->and($reports[0]['body'][0]['type'] ?? null)->toBe('csp-violation')
            ->and($reports[0]['body'][0]['body']['effectiveDirective'] ?? null)->toBe('object-src')
            ->and($reports[0]['body'][0]['body']['blockedURL'] ?? null)->toEndWith('/brand/logo.png')
            ->and(json_encode($reports))->not->toContain('s3cret');
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
            // Built for the primary host alone (ADR 0149): another host the
            // shop serves gets its own page from PHP, and one it does not
            // serve a 421 -- never the storefront's file.
            $port = (string) parse_url($m[1], PHP_URL_PORT);
            $admin = fetch("{$m[1]}/", ['Accept: text/html', "Host: admin.localhost:{$port}"]);
            $unserved = fetch("{$m[1]}/", ['Accept: text/html', "Host: evil.test:{$port}"]);
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
            ->and($built[2])->not->toBe($file)
            ->and($admin[2])->not->toBe($file)
            ->and($unserved[0])->toBe(421)
            ->and($unserved[2])->not->toBe($file);
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
        new Request('POST', BootstrapEndpoint::PATH, ['host' => 'localhost', 'content-type' => 'application/json', 'accept' => 'application/json', ...$headers], cookies: $cookies),
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
            ->and(FragmentManifest::fromFile("{$out}/fragments.json")->routes())->toEqualCanonicalizing(['orders.show', 'shop.catalog'])
            ->and(FragmentManifest::fromFile("{$out}/fragments.json")->boundariesFor('shop.catalog'))->toHaveCount(24);
    });

    // Revalidation, on demand: the catalog built again where it is served,
    // from the rows as they are now, and nothing built while it is fresh.
    it('builds the catalog again in place with static:revalidate, from the rows as they are now', function () use ($root) {
        $database = Shop::testDatabase();
        $out = scratch();
        build("{$out}/static", $database, "{$out}/fragments.json");
        $ids = array_column(FragmentManifest::fromFile("{$out}/fragments.json")->boundariesFor('shop.catalog'), 'id');
        $pdo = new \PDO("sqlite:{$database}");
        $product = (string) $pdo->query('SELECT id FROM products WHERE stock > 10 ORDER BY sku LIMIT 1')?->fetchColumn();
        $pdo->prepare('UPDATE products SET stock = 0 WHERE id = ?')->execute([$product]);
        $keyDir = scratch();
        $run = static function () use ($root, $out, $database, $keyDir): array {
            $process = proc_open(
                [PHP_BINARY, "{$root}/bin/tinvar", 'static:revalidate', "--dir={$out}/static", "--manifest={$out}/fragments.json"],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $root,
                [
                    ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true, 'SHOP_STATIC_DIR' => true]),
                    'SHOP_DATABASE' => $database,
                    'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
                ],
            );
            $output = (string) stream_get_contents($pipes[1]);

            return [proc_close($process), $output];
        };

        // Stale by age: written eleven minutes ago, past its ten.
        touch("{$out}/static/index.html", time() - 660);
        [$status, $output] = $run();
        $html = (string) file_get_contents("{$out}/static/index.html");
        preg_match('#href="/products/' . preg_quote($product, '#') . '".*?data-availability="([a-z_]+)"#s', $html, $shown);
        [$again, $quiet] = $run();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('built   /')
            ->and($shown[1] ?? null)->toBe('sold_out')
            // The same boundaries: each names a product, which did not change.
            ->and(array_column(FragmentManifest::fromFile("{$out}/fragments.json")->boundariesFor('shop.catalog'), 'id'))->toBe($ids)
            ->and($again)->toBe(0, $quiet)
            ->and($quiet)->toContain('Nothing is stale.')
            ->and(fn () => StaticBuilder::assertNothingPerVisitor($html, '/'))->not->toThrow(\RuntimeException::class);
    });

    // With SHOP_STATIC_DIR, the schedule keeps the built catalog fresh.
    it('schedules revalidation only where pages are served from files', function () use ($root) {
        $list = static function (array $env) use ($root): string {
            $process = proc_open(
                [PHP_BINARY, "{$root}/bin/tinvar", 'schedule:list'],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $root,
                [...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true, 'SHOP_STATIC_DIR' => true]), 'SHOP_DATABASE' => Shop::database(), ...$env],
            );
            $output = (string) stream_get_contents($pipes[1]);
            proc_close($process);

            return $output;
        };
        $keyDir = scratch();

        expect($list(['TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key", 'SHOP_STATIC_DIR' => scratch()]))->toContain('RevalidateStaticPages')
            ->and($list(['TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key"]))->not->toContain('RevalidateStaticPages');
    });

    it('refuses an argument it does not know', function () use ($root) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/tinvar") . ' build:static --into=x 2>&1', $output, $status);

        expect($status)->toBe(64)
            ->and(implode("\n", $output))->toContain('Usage: tinvar build:static');
    });
});
