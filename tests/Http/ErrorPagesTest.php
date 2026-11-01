<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "Error pages: map status codes
 * to an error #[Page] with shared data resolved even when routing failed."
 *
 * A small application: a global middleware that signs a visitor in, as a
 * session would; a root layout whose data is who is signed in and the
 * navigation; pages that fail each way; and error pages within `/`. The
 * renderer is a closure here -- what it is handed is what is asserted.
 */

namespace Tinvar\Tests\Http\ErrorPages;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Http\ErrorShell;
use Tinvar\Http\HttpError;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RendererException;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final readonly class SharedData extends Data
{
    /** @param list<string> $nav */
    public function __construct(public ?string $user, public array $nav) {}
}

final readonly class ErrorData extends Data
{
    public function __construct(public int $status, public string $code, public string $digest, public ?string $user, public string $path) {}
}

/** Signs in whoever the `user` cookie names: the session, for this application. */
final class StartSession implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->cookie('user');
        $this->auth->login($user === null ? null : new User($user));

        return $next($request);
    }
}

/** Refuses with the status the `refuse` query asks for, as a CSRF check or a throttle would. */
final class Refuse implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $status = (int) ($request->query['refuse'] ?? 0);
        if ($status > 0) {
            throw new HttpException($status, 'Refused by the test.', '', $status === 429 ? ['retry-after' => '42'] : []);
        }

        return $next($request);
    }
}

#[Layout('/', view: 'root/layout')]
final class RootLayout
{
    public function __construct(private readonly AuthContext $auth) {}

    public function __invoke(): SharedData
    {
        return new SharedData($this->auth->id(), ['Catalog', 'Orders']);
    }
}

#[Page('/refusing', view: 'refusing', name: 'refusing'), UseMiddleware(Refuse::class)]
final class RefusingPage {}

#[Page('/broken', view: 'broken', name: 'broken')]
final class BrokenPage
{
    public function __invoke(): never
    {
        throw new \LogicException('a bug in a loader');
    }
}

#[ErrorPage([403, 404, 419, 429, 500], view: 'errors/page', within: '/')]
final class Errors
{
    public function __invoke(HttpError $error, Request $request, SharedData $shared): ErrorData
    {
        return new ErrorData($error->status, $error->code, $error->digest, $shared->user, $request->path);
    }
}

/** What the renderer was asked to draw, request by request. */
final class Renders
{
    /** @var list<LoadedRoute> */
    public array $loaded = [];

    public bool $fail = false;

    /** @return \Closure(LoadedRoute, Request): Response */
    public function respond(): \Closure
    {
        return function (LoadedRoute $loaded, Request $request): Response {
            if ($this->fail) {
                throw RendererException::fromErrorFrame(['code' => 'render_failed']);
            }
            $this->loaded[] = $loaded;

            return Response::make(200, '<html>' . $loaded->chain->page->view . '</html>', [
                'content-type' => 'text/html; charset=utf-8',
                'content-security-policy' => "script-src 'nonce-abc' 'strict-dynamic'",
            ]);
        };
    }
}

/**
 * @param list<class-string> $routes
 * @param list<string> $logged digests the exception handler logged, in order
 */
function kernel(array $routes = [RootLayout::class, RefusingPage::class, BrokenPage::class, Errors::class], array &$logged = [], ?ErrorShell $shell = null): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $registry = new RouteRegistry();
    $registry->registerAll($routes);
    $auth = new AuthContext();

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $registry,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => match ($class) {
            StartSession::class => new StartSession($auth),
            RootLayout::class => new RootLayout($auth),
            default => new $class(),
        },
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [StartSession::class],
        log: static function (\Throwable $e, string $digest) use (&$logged): void {
            $logged[] = $digest;
        },
        errorShell: $shell,
    );
}

/** @param array<string, string> $query */
function document(string $path, array $query = [], ?string $user = 'ada'): Request
{
    return new Request('GET', $path, ['accept' => 'text/html,application/xhtml+xml'], $query, [], $user === null ? [] : ['user' => $user]);
}

it('answers each status with the error page, the layout data resolved for the signed-in visitor', function (int $status, string $path, array $query, string $code) {
    $logged = [];
    $kernel = kernel(logged: $logged);
    $renders = new Renders();

    $response = $kernel->handle(document($path, $query), $renders->respond());

    expect($response->status)->toBe($status)
        ->and($response->body())->toBe('<html>errors/page</html>')
        ->and($renders->loaded)->toHaveCount(1);

    $loaded = $renders->loaded[0];
    $page = $loaded->page;
    expect($page)->toBeInstanceOf(ErrorData::class);
    \assert($page instanceof ErrorData);
    expect($page->status)->toBe($status)
        ->and($page->code)->toBe($code)
        ->and($page->user)->toBe('ada')
        ->and($page->path)->toBe($path)
        // The reference on the page is the line in the log.
        ->and($page->digest)->toBe($logged[0])
        // The layout's own data is there for the layout to draw: the header
        // knows who is signed in, and the navigation.
        ->and($loaded->layouts['root'])->toEqual(new SharedData('ada', ['Catalog', 'Orders']))
        ->and($loaded->chain->page->view)->toBe('errors/page');
})->with([
    'forbidden' => [403, '/refusing', ['refuse' => '403'], 'forbidden'],
    // No route matched at all: the layout still ran, as the visitor.
    'not found, where routing failed' => [404, '/no/such/page', [], 'not_found'],
    'page expired' => [419, '/refusing', ['refuse' => '419'], 'csrf_token_mismatch'],
    'too many requests' => [429, '/refusing', ['refuse' => '429'], 'too_many_requests'],
    'server error' => [500, '/broken', [], 'internal_error'],
]);

it('resolves the layout data for a visitor who is not signed in, too', function () {
    $renders = new Renders();

    kernel()->handle(document('/missing', user: null), $renders->respond());

    expect($renders->loaded[0]->layouts['root'])->toEqual(new SharedData(null, ['Catalog', 'Orders']));
});

it('answers any 5xx no page names with the page for 500', function () {
    $renders = new Renders();

    $response = kernel()->handle(document('/refusing', ['refuse' => '503']), $renders->respond());

    expect($response->status)->toBe(503)
        ->and($renders->loaded[0]->page)->toBeInstanceOf(ErrorData::class);
});

it('carries what the refusal said besides its status, and is kept by no shared cache', function () {
    $response = kernel()->handle(document('/refusing', ['refuse' => '429']), new Renders()->respond());

    expect($response->header('retry-after'))->toBe('42')
        ->and($response->header('cache-control'))->toBe('private, no-store')
        ->and($response->header('content-security-policy'))->toBe("script-src 'nonce-abc' 'strict-dynamic'");
});

it('leaves a status no page names as the framework answers it', function () {
    $renders = new Renders();

    $response = kernel()->handle(document('/refusing', ['refuse' => '418']), $renders->respond());

    expect($response->status)->toBe(418)
        ->and($renders->loaded)->toBe([])
        ->and($response->body())->toContain('<h1>418</h1>');
});

it('draws no error page for anyone but a browser asking for a document', function (Request $request) {
    $renders = new Renders();

    $response = kernel()->handle($request, $renders->respond());

    expect($renders->loaded)->toBe([])
        ->and($response->status)->toBeGreaterThanOrEqual(400);
})->with([
    'an API client' => [new Request('GET', '/missing', ['accept' => 'application/json'])],
    'a client navigation' => [new Request('GET', '/missing', ['accept' => 'text/html', 'rsc' => '1'])],
    'a post' => [new Request('POST', '/missing', ['accept' => 'text/html'])],
    'an endpoint' => [new Request('GET', '/_query/nope', ['accept' => 'text/html'])],
]);

// "PHP renders an error page in its own shell when the renderer fails."
it('falls back to the shell PHP draws alone when the error page cannot be rendered', function () {
    $logged = [];
    $kernel = kernel(logged: $logged, shell: new ErrorShell('Acme', '<svg id="mark"></svg>', '/', 'Back to Acme'));
    $renders = new Renders();
    $renders->fail = true;

    $response = $kernel->handle(document('/missing'), $renders->respond());

    expect($response->status)->toBe(404)
        ->and($response->header('content-type'))->toBe('text/html; charset=utf-8')
        ->and($response->body())->toContain('<svg id="mark"></svg>')
        ->and($response->body())->toContain('404 · Not found')
        ->and($response->body())->toContain('<a class="back" href="/">Back to Acme</a>')
        // Under the original failure's digest, which is the first line
        // logged; the second says why the page could not be drawn.
        ->and($response->body())->toContain("<code>{$logged[0]}</code>")
        ->and($logged)->toHaveCount(2)
        ->and($response->header('content-security-policy'))->toStartWith("default-src 'none'; style-src 'nonce-");
});

it('goes straight to the shell when the renderer is what failed, and gone', function (\Throwable $failure) {
    $renders = new Renders();
    $calls = 0;
    $respond = static function () use (&$calls, $failure): Response {
        $calls++;

        throw $failure;
    };

    $response = kernel()->handle(document('/refusing'), $respond);

    // Asked once, for the page; never again for the error page.
    expect($calls)->toBe(1)
        ->and($response->status)->toBe(500)
        ->and($response->body())->toContain('500 · Server error');
})->with([
    'unreachable' => [RendererException::cannotConnect('/tmp/none.sock', 'no socket at that path')],
    'past its deadline' => [RendererException::deadline(15.0)],
    'cut short' => [RendererException::closedEarly()],
    'breaking the protocol' => [\Tinvar\Protocol\ProtocolException::wrongDirection('render')],
]);

it('draws an error page for a status on request, as the visitor', function () {
    $renders = new Renders();

    $response = kernel()->errorPage(document('/anywhere'), 419, $renders->respond());

    $page = $renders->loaded[0]->page;
    \assert($page instanceof ErrorData);
    expect($response->status)->toBe(419)
        ->and($page->user)->toBe('ada')
        ->and($page->code)->toBe('csrf_token_mismatch');
});

it('is never matched by a URL', function () {
    $routes = new RouteRegistry();
    $routes->registerAll([RootLayout::class, Errors::class]);

    expect($routes->match('/'))->toBeNull()
        ->and($routes->pages())->toBe([]);
});

describe('what an error page may not declare', function () {
    it('refuses a path with parameters to sit within', function () {
        new RouteRegistry()->register(WithinParameters::class);
    })->throws(RoutingException::class, 'has parameters');

    it('refuses middleware of its own', function () {
        new RouteRegistry()->register(WithMiddleware::class);
    })->throws(RoutingException::class, 'declares middleware');

    it('refuses a status outside 400 to 599', function () {
        new RouteRegistry()->register(ForAnOk::class);
    })->throws(RoutingException::class, 'an error page is for 400 to 599');

    // Review: each status was kept as it was checked, so a page refused for
    // a later one stayed the error page for those before it -- in a
    // registry `tinvar check` goes on with after a refusal.
    it('keeps no status of a page it refuses', function () {
        $routes = new RouteRegistry();

        expect(fn () => $routes->register(PartlyOk::class))->toThrow(RoutingException::class, 'an error page is for 400 to 599')
            ->and($routes->errorChain(404))->toBeNull()
            ->and($routes->errorPages())->toBe([]);
    });

    it('refuses two pages for one status', function () {
        new RouteRegistry()->registerAll([Errors::class, AnotherNotFound::class]);
    })->throws(RoutingException::class, 'are the error page for 404');

    it('refuses a loader asking for what nothing supplies', function () {
        new RouteRegistry()->register(WantsAString::class);
    })->throws(RoutingException::class);

    it('refuses to sit within a layout that runs middleware, when the Kernel is built', function () {
        kernel([GuardedLayout::class, GuardedErrors::class]);
    })->throws(RoutingException::class, 'runs middleware, in a panel or for a tenant');
});

#[ErrorPage([404], view: 'errors/x', within: '/sites/{site}')]
final class WithinParameters {}

#[ErrorPage([404], view: 'errors/x'), UseMiddleware(Refuse::class)]
final class WithMiddleware {}

#[ErrorPage([200], view: 'errors/x')]
final class ForAnOk {}

#[ErrorPage([404], view: 'errors/other')]
final class AnotherNotFound {}

#[ErrorPage([404, 200], view: 'errors/x')]
final class PartlyOk {}

#[ErrorPage([404], view: 'errors/x')]
final class WantsAString
{
    public function __invoke(string $what): ErrorData
    {
        throw new \LogicException($what);
    }
}

#[Layout('/account', view: 'account/layout'), UseMiddleware(Refuse::class)]
final class GuardedLayout {}

#[ErrorPage([404], view: 'errors/x', within: '/account')]
final class GuardedErrors {}
