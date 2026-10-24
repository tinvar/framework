<?php

declare(strict_types=1);

/**
 * The shop's fragment manifest, produced by its build, and the bootstrap
 * endpoint it mounts -- end to end, through the real renderer.
 *
 * The shop used to declare its one deferring route by hand. `tinvar
 * build:fragments` now writes the manifest from the renderer build's
 * deferred.json and the shop's routes, and this holds it to what the hand
 * declaration said. With a statically built page in it, the Kernel mounts
 * /_bootstrap, whose tokens a visitor then loads fragments with.
 */

namespace Tinvar\Tests\Integration\ShopFragmentManifest;

use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Endpoints\BootstrapEndpoint;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Support\Signer;

const KEY = 'shop-fragment-manifest-key-of-32-bytes';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** A directory removed when the test process ends. */
function scratch(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-shop-manifest-' . bin2hex(random_bytes(4));
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
 * `bin/tinvar build:fragments` over a copy of the shop's build, so the
 * manifest the other tests read is left alone.
 *
 * @return array{int, string, string} the exit status, the output, and the build directory
 */
function produce(): array
{
    $root = \dirname(__DIR__, 4);
    $build = scratch();
    mkdir("{$build}/rsc");
    copy(Shop::root() . '/dist/rsc/deferred.json', "{$build}/rsc/deferred.json");
    // And the panels it kept apart, which build:fragments holds to the routes'.
    copy(Shop::root() . '/dist/rsc/panels.json', "{$build}/rsc/panels.json");

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'build:fragments', "--build={$build}"],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
    );
    $output = (string) stream_get_contents($pipes[1]);

    return [proc_close($process), $output, $build];
}

/** @param array<string, string> $cookies */
function keep(Response $response, array &$cookies): Response
{
    foreach ($response->cookies() as $name => $line) {
        $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
    }

    return $response;
}

function csrf(array $cookies): string
{
    return (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', cookies: $cookies));
}

/**
 * The demo customer signed in, and one of their orders.
 *
 * @return array{array<string, string>, string} cookies, and an order id
 */
function signedIn(Kernel $kernel): array
{
    $cookies = [];
    keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'], cookies: $cookies)), $cookies);
    $token = csrf($cookies);

    $signIn = keep($kernel->handle(
        new Request(
            'POST',
            '/_action/account.signIn',
            ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
            cookies: [...$cookies, '__Host-XSRF-TOKEN' => $token],
        ),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/orders'], JSON_THROW_ON_ERROR),
    ), $cookies);
    expect($signIn->status)->toBe(303, $signIn->body());

    $order = new \PDO('sqlite:' . Shop::database())->query(
        "SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.email = '" . DemoAccount::EMAIL . "' LIMIT 1",
    )?->fetchColumn();

    return [$cookies, (string) $order];
}

/** @param array<string, string> $cookies */
function bootstrap(Kernel $kernel, array $cookies, string $order): Response
{
    $token = csrf($cookies);

    return $kernel->handle(
        new Request(
            'POST',
            BootstrapEndpoint::PATH,
            ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token],
            cookies: [...$cookies, '__Host-XSRF-TOKEN' => $token],
        ),
        json_encode(['route' => 'orders.show', 'params' => ['order' => $order]], JSON_THROW_ON_ERROR),
    );
}

describe('the produced manifest', function () {
    // What the shop declared by hand before: new FragmentManifest(routes:
    // ['orders.show']) -- with the one component that page renders, and the
    // catalog, whose cards defer their availability when it is built to a
    // file, and may mint for that component alone.
    it('declares the order page and the catalog, each with its one component', function () {
        [$status, $output, $build] = produce();
        $manifest = FragmentManifest::fromFile("{$build}/fragments.json");

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('defers  orders.show (ShippingUpdates)')
            ->and($output)->toContain('defers  shop.catalog (Availability)')
            ->and($manifest->routes())->toEqualCanonicalizing(['orders.show', 'shop.catalog'])
            ->and($manifest->componentsFor('orders.show'))->toBe(['ShippingUpdates'])
            ->and($manifest->componentsFor('shop.catalog'))->toBe(['Availability'])
            ->and($manifest->defersOn('shop.product'))->toBeFalse()
            ->and($manifest->hasAny())->toBeFalse('no page is built statically');
    });

    it('comes from views the renderer build reached, not a list', function () {
        $views = json_decode((string) file_get_contents(Shop::root() . '/dist/rsc/deferred.json'), true)['views'];

        expect($views['shop/order'])->toBe(['ShippingUpdates'])
            ->and($views['shop/catalog'])->toBe(['Availability'])
            ->and($views['shop/product'])->toBe([])
            ->and($views['shop/layout'])->toBe([]);
    });

    // The shop's own Kernel reads the file the build wrote: the order page's
    // boundary still mints, and its fragment still renders.
    it('is what the shop reads, so the order page still loads its shipping panel', function () {
        $renderer = Renderer::start('msgpack', 'manifest-order');
        try {
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());
            [$cookies, $order] = signedIn($kernel);
            $page = $kernel->handle(new Request('GET', "/orders/{$order}", ['accept' => 'text/html'], cookies: $cookies));
        } finally {
            $renderer->stop();
        }

        expect($page->status)->toBe(200, $page->body())
            ->and($page->body())->toMatch('/v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/');
    });

    it('refuses to write over a build that has not listed its boundaries', function () {
        $root = \dirname(__DIR__, 4);
        $build = scratch();
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/tinvar") . ' build:fragments --build=' . escapeshellarg($build) . ' 2>&1', $output, $status);

        expect($status)->toBe(1)
            ->and(implode("\n", $output))->toContain('deferredBoundaries()')
            ->and(is_file("{$build}/fragments.json"))->toBeFalse();
    });
});

describe('the bootstrap endpoint', function () {
    it('is not mounted while no page is built statically', function () {
        $kernel = Kernel::fromEnvironment('/tmp/tinvar-none.sock', key: KEY, database: Shop::database());
        [$cookies, $order] = signedIn($kernel);

        expect(bootstrap($kernel, $cookies, $order)->status)->not->toBe(200);
    });

    // A page built statically ships its boundaries without tokens; the
    // visitor's own come from /_bootstrap, minted for their session, and load
    // through /_fragment like any other -- here through the real renderer.
    it('is mounted for a built page, and its tokens load the fragment', function () {
        [, , $build] = produce();
        $manifest = FragmentManifest::fromFile("{$build}/fragments.json");

        $renderer = Renderer::start('msgpack', 'manifest-bootstrap');
        try {
            // Signed in first: the page is the customer's order, whose id is
            // known only then. The session is the shop's, kept in its table,
            // so the Kernel built after it honours the same cookies.
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), manifest: $manifest);
            [$cookies, $order] = signedIn($kernel);

            // As a static build records the page it rendered: its route, the
            // params it was built for, and each boundary's component and props.
            $manifest->declare('orders.show', 'shipping-updates', 'ShippingUpdates', ['order' => $order], ['order' => $order]);
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), manifest: $manifest);

            $bootstrapped = bootstrap($kernel, $cookies, $order);
            $tokens = json_decode($bootstrapped->body(), true)['tokens'] ?? [];
            $fragment = $kernel->handle(new Request(
                'GET',
                FragmentEndpoint::PREFIX . ($tokens['shipping-updates'] ?? 'none'),
                ['rsc' => '1', 'accept' => 'text/x-component'],
                cookies: $cookies,
            ));
        } finally {
            $renderer->stop();
        }

        $session = (string) Kernel::sessionFrom($cookies[Kernel::SESSION_COOKIE], KEY);
        $signer = new Signer(KEY, FragmentToken::PURPOSE);

        expect($bootstrapped->status)->toBe(200, $bootstrapped->body())
            ->and($bootstrapped->header('cache-control'))->toBe('private, no-store')
            ->and(array_keys($tokens))->toBe(['shipping-updates'])
            ->and(FragmentToken::decode($tokens['shipping-updates'], $signer)->belongsTo($signer, $session))->toBeTrue()
            ->and($fragment->status)->toBe(200, $fragment->body())
            ->and($fragment->body())->toContain('data-shipping-updates');
    });
});
