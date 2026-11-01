<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "Show raw errors in the dev
 * overlay, CSP-nonce aware." And, under "HTTP": "Renderer stacks, source
 * locations and hints go to logs in production, never to the browser."
 *
 * Both halves: the overlay is there, whole, in dev; and outside dev not one
 * thing it would have shown reaches the response.
 */

namespace Tinvar\Tests\Http\DevOverlay;

use Shop\Kernel as ShopKernel;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\DevOverlay;
use Tinvar\Http\HttpError;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RendererException;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\DevMode;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class Failure extends Data
{
    public function __construct(public int $status, public string $digest) {}
}

#[Page('/broken', view: 'broken', name: 'broken')]
final class BrokenPage
{
    public function __invoke(): never
    {
        throw new \LogicException('the price table has no row for SKU-14');
    }
}

#[Page('/catalog', view: 'catalog', name: 'catalog')]
final class CatalogPage {}

#[ErrorPage([404, 500], view: 'errors/page')]
final class Errors
{
    public function __invoke(HttpError $error): Failure
    {
        return new Failure($error->status, $error->digest);
    }
}

/** The renderer: fails a render of the catalog as the frame says, and draws anything else. */
function respond(?RendererException $failure = null): \Closure
{
    return static function (LoadedRoute $loaded) use ($failure): Response {
        if ($failure !== null && $loaded->chain->page->view === 'catalog') {
            throw $failure;
        }

        return Response::make(200, '<!doctype html><html><head></head><body><main>' . $loaded->chain->page->view . '</main></body></html>', [
            'content-type' => 'text/html; charset=utf-8',
            'content-security-policy' => "script-src 'nonce-R4nd0m+N/once==' 'strict-dynamic'; object-src 'none'",
        ]);
    };
}

/** @param list<class-string> $routes */
function kernel(bool $dev, array $routes = [BrokenPage::class, CatalogPage::class, Errors::class]): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $registry = new RouteRegistry();
    $registry->registerAll($routes);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $registry,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        dev: $dev,
    );
}

function document(string $path): Request
{
    return new Request('GET', $path, ['host' => 'localhost', 'accept' => 'text/html']);
}

/** What the renderer sends about a component that read `window`, under debug. */
function windowFailure(): RendererException
{
    return RendererException::fromErrorFrame([
        'code' => 'browser_api_on_server',
        'digest' => '9f2c1a7e',
        'message' => 'window is not defined',
        'stack' => "ReferenceError: window is not defined\n    at Width (/app/resources/components/Width.tsx:3:20)",
        'file' => '/app/resources/components/Width.tsx',
        'line' => 3,
    ], debug: true);
}

it('lays the raw PHP failure over the error page in dev: class, message, file and line, stack', function () {
    $response = kernel(dev: true)->handle(document('/broken'), respond());
    $body = $response->body();

    expect($response->status)->toBe(500)
        ->and($body)->toContain('<main>errors/page</main>')
        ->and($body)->toContain('id="' . DevOverlay::ID . '"')
        ->and($body)->toContain('LogicException: the price table has no row for SKU-14')
        ->and($body)->toContain(__FILE__ . ':')
        ->and($body)->toContain('BrokenPage-&gt;__invoke()')
        // At the end of the body, after the page React will hydrate.
        ->and(strpos($body, DevOverlay::ID))->toBeGreaterThan(strpos($body, '<main>'))
        ->and(strpos($body, DevOverlay::ID))->toBeLessThan(strpos($body, '</body>'));
});

// Under strict-dynamic only a script carrying the response's nonce runs, so
// the overlay's style and its close button's script carry that one.
it('carries the nonce the response\'s own policy allows, and asks for no other', function () {
    $response = kernel(dev: true)->handle(document('/broken'), respond());

    expect($response->header('content-security-policy'))->toBe("script-src 'nonce-R4nd0m+N/once==' 'strict-dynamic'; object-src 'none'");
    preg_match_all('/<(style|script)\b([^>]*)>/', explode('id="' . DevOverlay::ID . '"', $response->body())[1], $tags, PREG_SET_ORDER);
    expect($tags)->toHaveCount(2);
    foreach ($tags as [, , $attributes]) {
        expect($attributes)->toBe(' nonce="R4nd0m+N/once=="');
    }
});

it('names a render failure\'s class with its hint, and the component\'s own source line and stack', function () {
    $response = kernel(dev: true)->handle(document('/catalog'), respond(windowFailure()));
    $body = $response->body();

    expect($body)->toContain('Render failure · browser_api_on_server')
        ->and($body)->toContain('Browser API used on the server')
        ->and($body)->toContain('Every component renders on the server first')
        ->and($body)->toContain('/app/resources/components/Width.tsx:3')
        ->and($body)->toContain('at Width (/app/resources/components/Width.tsx:3:20)')
        ->and($body)->toContain('window is not defined');
});

it('carries the overlay on the shell when the failure has no error page of its own', function () {
    $response = kernel(dev: true, routes: [BrokenPage::class])->handle(document('/broken'), respond());

    expect($response->status)->toBe(500)
        ->and($response->body())->toContain('500 · Server error')
        ->and($response->body())->toContain('LogicException: the price table has no row for SKU-14');
    $nonce = DevOverlay::nonceOf($response->header('content-security-policy'));
    expect($nonce)->not->toBeNull()
        ->and($response->body())->toContain('<script nonce="' . $nonce . '">');
});

// Dev listens on loopback, and a page that points a name of its own at
// 127.0.0.1 -- DNS rebinding -- reaches it from its visitor's machine, same
// origin. What it cannot change is the Host its requests name, which the
// overlay -- the raw failure, its files and its stack -- now asks about.
it('lays the overlay over a page only for a request that names this machine', function (string $host, bool $shown) {
    $request = new Request('GET', '/broken', ['accept' => 'text/html', 'host' => $host], remoteAddress: '127.0.0.1');
    $body = kernel(dev: true)->handle($request, respond())->body();

    expect(str_contains($body, 'id="' . DevOverlay::ID . '"'))->toBe($shown)
        ->and(str_contains($body, 'SKU-14'))->toBe($shown)
        ->and(str_contains($body, __FILE__))->toBe($shown)
        ->and($body)->toContain('<main>errors/page</main>');
})->with([
    'localhost' => ['localhost:8000', true],
    'an address' => ['127.0.0.1:8000', true],
    'a name under localhost' => ['admin.localhost:8000', true],
    'a rebound name' => ['rebound.attacker.example:8000', false],
    'a name ending like localhost' => ['evil-localhost:8000', false],
]);

// Dev may listen beyond loopback -- a container's port, a LAN address -- and
// a peer elsewhere can name `localhost`, or nothing, as easily as anything.
// Its address is the one thing it cannot choose: one known and not loopback
// is shown no failure, whatever it names. One unknown -- a server adapter
// that reports none -- is held to the name alone, as before.
it('lays the overlay over a page only for a peer on this machine, or one no address is known for', function (?string $remote, ?string $host, bool $shown) {
    $headers = ['accept' => 'text/html', ...($host === null ? [] : ['host' => $host])];
    $body = kernel(dev: true)->handle(new Request('GET', '/broken', $headers, remoteAddress: $remote), respond())->body();

    expect(str_contains($body, 'id="' . DevOverlay::ID . '"'))->toBe($shown)
        ->and(str_contains($body, 'SKU-14'))->toBe($shown)
        ->and(str_contains($body, __FILE__))->toBe($shown);
})->with([
    'another machine naming localhost' => ['10.0.0.7', 'localhost:8000', false],
    'another machine naming nothing' => ['192.168.1.20', null, false],
    'another machine over IPv6' => ['2001:db8::7', 'localhost', false],
    'loopback over IPv6' => ['::1', 'localhost:8000', true],
    'loopback naming nothing' => ['127.0.0.1', null, true],
    'an unknown peer naming localhost' => [null, 'localhost:8000', true],
    'an unknown peer naming another host' => [null, 'rebound.attacker.example', false],
]);

it('lays nothing over an answer: a 404 is not a bug', function () {
    $body = kernel(dev: true)->handle(document('/nowhere'), respond())->body();

    expect($body)->toContain('<main>errors/page</main>')
        ->and($body)->not->toContain(DevOverlay::ID);
});

// Never in production: not the overlay, and not one thing it would show.
it('shows none of it outside dev', function (string $path, ?RendererException $failure, array $secrets) {
    $response = kernel(dev: false)->handle(document($path), respond($failure));
    $body = $response->body();

    expect($response->status)->toBe(500)
        ->and($body)->toContain('<main>errors/page</main>')
        ->and($body)->not->toContain(DevOverlay::ID)
        ->and($body)->not->toContain('<pre');
    foreach ($secrets as $secret) {
        expect($body)->not->toContain($secret);
    }
})->with([
    'a PHP failure' => ['/broken', null, ['LogicException', 'SKU-14', __FILE__, 'BrokenPage']],
    'a render failure' => ['/catalog', windowFailure(), ['window is not defined', 'Width.tsx', 'browser_api_on_server', 'Every component renders']],
]);

it('shows none of it outside dev when there is no error page either', function () {
    $body = kernel(dev: false, routes: [BrokenPage::class])->handle(document('/broken'), respond())->body();

    expect($body)->not->toContain(DevOverlay::ID)->and($body)->not->toContain('SKU-14');
});

// The shop's own wiring: dev from TINVAR_DEV, and the renderer it cannot
// reach named with its hint.
it('overlays the shop\'s shell in dev, and only in dev, when the renderer is gone', function () {
    $key = str_repeat('k', 40);
    $database = \Tinvar\Bench\Shop::database(customers: 1);
    $dev = ShopKernel::fromEnvironment('/tmp/tinvar-dev-overlay-none.sock', key: $key, database: $database, dev: true);
    $prod = ShopKernel::fromEnvironment('/tmp/tinvar-dev-overlay-none.sock', key: $key, database: $database, dev: false);

    $overlaid = $dev->handle(document('/'))->body();
    $plain = $prod->handle(document('/'))->body();

    expect($overlaid)->toContain('Renderer unreachable')
        ->and($overlaid)->toContain('Nothing is listening on the renderer')
        ->and($overlaid)->toContain('/tmp/tinvar-dev-overlay-none.sock')
        ->and($plain)->toContain('500 · Server error')
        ->and($plain)->not->toContain(DevOverlay::ID)
        ->and($plain)->not->toContain('tinvar-dev-overlay-none.sock');
});

describe('dev mode, from the environment', function () {
    it('is off unless TINVAR_DEV says so', function () {
        expect(DevMode::fromEnvironment([]))->toBeFalse()
            ->and(DevMode::fromEnvironment(['TINVAR_DEV' => '0']))->toBeFalse()
            ->and(DevMode::fromEnvironment(['TINVAR_DEV' => '1', 'NODE_ENV' => 'development']))->toBeTrue();
    });

    it('is refused, loudly, beside any sign of production', function (string $marker) {
        expect(fn () => DevMode::fromEnvironment(['TINVAR_DEV' => '1', $marker => 'Production']))
            ->toThrow(\RuntimeException::class, "TINVAR_DEV=1 is refused: {$marker}=production");
    })->with(DevMode::PRODUCTION_MARKERS);

    it('stops the shop from booting rather than serving the overlay in production', function () {
        putenv('TINVAR_DEV=1');
        putenv('TINVAR_ENV=production');
        // A production build has its discovery manifest (ADR 0146).
        putenv('TINVAR_BUILD_DIR=' . \Tinvar\Bench\Shop::productionBuild());
        try {
            expect(fn () => ShopKernel::fromEnvironment('/tmp/none.sock', key: str_repeat('k', 40), database: ':memory:'))
                ->toThrow(\RuntimeException::class, 'TINVAR_DEV=1 is refused');
        } finally {
            putenv('TINVAR_DEV');
            putenv('TINVAR_ENV');
            putenv('TINVAR_BUILD_DIR');
        }
    });
});
