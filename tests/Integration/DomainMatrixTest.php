<?php

declare(strict_types=1);

/**
 * #[Domain] across every kind of routable thing, through one Kernel
 * (ADR 0149).
 *
 * Four kinds of host: the primary host (`example.test`), a fixed host
 * (`admin.example.test`), a host a parameter takes (`{account}.example.test`,
 * the parameter reaching the handler) and a separately configured domain
 * (`shop.other.test`, as `{other}`). For each kind of entry point there is
 * one declaring a host and one declaring none, and each is asked:
 *
 *   (a) served on its own host;
 *   (b) refused on every other: a 404 on a host the application serves,
 *       a 421 on one it does not, a 400 for a request naming none;
 *   (c) its tokens carry the host, and are refused on any other;
 *   (d) its links, from every host -- a path on its own, an absolute URL
 *       from another, a parameter's host written with its parameters.
 *
 * The client side of (d) and (e) -- route(), endpoint() and the renderer's
 * hosts -- is proved in packages/react, packages/renderer and
 * packages/codegen; transfers in tests/Transfers/HostedExportLinkTest.php,
 * uploads in tests/Uploads/UploadHostTest.php, the query cache in
 * tests/Query/QueryHostCacheTest.php, the toolbar in tests/Dev, and the
 * shop's own hosts, under php -S and FrankenPHP, in BrowserNavigationTest.
 */

namespace Tinvar\Tests\Integration\DomainMatrix;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Channel;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HttpError;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TrustedProxies;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

const KEY = 'domain-matrix-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';

const PRIMARY = 'example.test';
const ADMIN = 'admin.example.test';
const ACME = 'acme.example.test';
const GLOBEX = 'globex.example.test';
const OTHER = 'shop.other.test';

/** Every host the application serves, by the kind of host it is. */
const HOSTS = ['primary' => PRIMARY, 'fixed' => ADMIN, 'parameter' => ACME, 'configured' => OTHER];

// ---------------------------------------------------------------- data

final readonly class PageData extends Data
{
    public function __construct(public string $page, public string $account = '') {}
}

final readonly class LayoutData extends Data
{
    public function __construct(public string $layout) {}
}

final readonly class NestedLayoutData extends Data
{
    public function __construct(public string $layout) {}
}

final readonly class ErrorData extends Data
{
    public function __construct(public string $page, public int $status) {}
}

final readonly class FeedData extends Data
{
    public function __construct(public string $text) {}
}

// ------------------------------------------------------------- layouts

#[Layout('/', view: 'shop/layout')]
final class ShopLayout
{
    public function __invoke(): LayoutData
    {
        return new LayoutData('shop');
    }
}

#[Layout('/', view: 'admin/layout'), Domain('admin.{root}'), Panel('staff')]
final class AdminLayout
{
    public function __invoke(): LayoutData
    {
        return new LayoutData('admin');
    }
}

/** Nested under the admin host's root layout. */
#[Layout('/orders', view: 'admin-orders/layout'), Domain('admin.{root}'), Panel('staff')]
final class AdminOrdersLayout
{
    public function __invoke(): NestedLayoutData
    {
        return new NestedLayoutData('admin-orders');
    }
}

#[Layout('/', view: 'account/layout'), Domain('{account}.{root}')]
final class AccountLayout
{
    public function __invoke(string $account): LayoutData
    {
        return new LayoutData('account:' . $account);
    }
}

#[Layout('/', view: 'other/layout'), Domain('{other}')]
final class OtherLayout
{
    public function __invoke(): LayoutData
    {
        return new LayoutData('other');
    }
}

// --------------------------------------------------------------- pages

#[Page('/', view: 'shop/home', name: 'shop.home'), NoAuthorization(reason: 'a test page')]
final class ShopHome
{
    public function __invoke(): PageData
    {
        return new PageData('shop.home');
    }
}

#[Page('/orders', view: 'shop/orders', name: 'shop.orders'), NoAuthorization(reason: 'a test page')]
final class ShopOrders
{
    public function __invoke(): PageData
    {
        return new PageData('shop.orders');
    }
}

#[Page('/', view: 'admin/home', name: 'admin.home'), Domain('admin.{root}'), Panel('staff'), NoAuthorization(reason: 'a test page')]
final class AdminHome
{
    public function __invoke(): PageData
    {
        return new PageData('admin.home');
    }
}

/** The same path as the storefront's, on the admin host: two pages. */
#[Page('/orders', view: 'admin/orders', name: 'admin.orders'), Domain('admin.{root}'), Panel('staff'), NoAuthorization(reason: 'a test page')]
final class AdminOrders
{
    public function __invoke(): PageData
    {
        return new PageData('admin.orders');
    }
}

#[Page('/orders/{order}', view: 'admin/order', name: 'admin.order'), Domain('admin.{root}'), Panel('staff'), NoAuthorization(reason: 'a test page')]
final class AdminOrder
{
    public function __invoke(string $order): PageData
    {
        return new PageData('admin.order:' . $order);
    }
}

#[Page('/', view: 'account/home', name: 'account.home'), Domain('{account}.{root}'), NoAuthorization(reason: 'a test page')]
final class AccountHome
{
    public function __invoke(string $account): PageData
    {
        return new PageData('account.home', $account);
    }
}

#[Page('/invoices/{invoice}', view: 'account/invoice', name: 'account.invoice'), Domain('{account}.{root}'), NoAuthorization(reason: 'a test page')]
final class AccountInvoice
{
    public function __invoke(string $account, string $invoice): PageData
    {
        return new PageData('account.invoice:' . $invoice, $account);
    }
}

#[Page('/', view: 'other/home', name: 'other.home'), Domain('{other}'), NoAuthorization(reason: 'a test page')]
final class OtherHome
{
    public function __invoke(): PageData
    {
        return new PageData('other.home');
    }
}

/** A section put on the admin host by an abstract base class. */
#[Domain('admin.{root}')]
abstract class AdminSection {}

#[Page('/reports', view: 'admin/reports', name: 'admin.reports'), Panel('staff'), NoAuthorization(reason: 'a test page')]
final class AdminReports extends AdminSection
{
    public function __invoke(): PageData
    {
        return new PageData('admin.reports');
    }
}

/** One page of the section that says otherwise: on each account's host. */
#[Page('/reports', view: 'account/reports', name: 'account.reports'), Domain('{account}.{root}'), NoAuthorization(reason: 'a test page')]
final class AccountReports extends AdminSection
{
    public function __invoke(string $account): PageData
    {
        return new PageData('account.reports', $account);
    }
}

// --------------------------------------------------------- error pages

#[ErrorPage([404, 500], view: 'errors/page'), NoAuthorization(reason: 'an error page')]
final class ShopErrorPage
{
    public function __invoke(HttpError $error): ErrorData
    {
        return new ErrorData('shop.error', $error->status);
    }
}

#[ErrorPage([404, 500], view: 'admin/error'), Domain('admin.{root}'), NoAuthorization(reason: 'an error page')]
final class AdminErrorPage
{
    public function __invoke(HttpError $error): ErrorData
    {
        return new ErrorData('admin.error', $error->status);
    }
}

#[ErrorPage([404], view: 'account/error'), Domain('{account}.{root}'), NoAuthorization(reason: 'an error page')]
final class AccountErrorPage
{
    public function __invoke(HttpError $error): ErrorData
    {
        return new ErrorData('account.error', $error->status);
    }
}

// ------------------------------------------------------------- actions

final class Redirects
{
    public static string $location = '';
}

final class AnyActions
{
    #[Action(name: 'any.touch'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function touch(): ActionResult
    {
        return ActionResult::revalidate('account.home');
    }

    #[Action(name: 'any.go'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function go(): ActionResult
    {
        return ActionResult::location(Redirects::$location);
    }

    #[Action(name: 'any.back'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function back(): ActionResult
    {
        return ActionResult::back();
    }

    #[Action(name: 'any.redirect'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function redirect(): ActionResult
    {
        return ActionResult::redirect('/orders');
    }
}

final class AdminActions
{
    #[Action(name: 'admin.touch'), Domain('admin.{root}'), Panel('staff'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function touch(): ActionResult
    {
        return ActionResult::revalidate('admin.orders');
    }
}

final class AccountActions
{
    #[Action(name: 'account.touch'), Domain('{account}.{root}'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function touch(): ActionResult
    {
        return ActionResult::revalidate('account.home');
    }
}

// ------------------------------------------------------------- queries

#[Query(name: 'any.count'), PublicAccess, NoAuthorization(reason: 'a test query')]
final class AnyCount
{
    public function __invoke(): int
    {
        return 7;
    }
}

#[Query(name: 'admin.revenue'), Domain('admin.{root}'), Panel('staff'), PublicAccess, NoAuthorization(reason: 'a test query')]
final class AdminRevenue
{
    public function __invoke(): int
    {
        return 42;
    }
}

#[Query(name: 'account.balance'), Domain('{account}.{root}'), PublicAccess, NoAuthorization(reason: 'a test query')]
final class AccountBalance
{
    public function __invoke(): int
    {
        return 3;
    }
}

// ----------------------------------------------------------- endpoints

final class Endpoints
{
    #[Get('/api/ping', name: 'shop.ping'), PublicAccess, NoAuthorization(reason: 'a test endpoint')]
    public function shopPing(): FeedData
    {
        return new FeedData('shop');
    }

    #[Get('/api/ping', name: 'admin.ping'), Domain('admin.{root}'), Panel('staff'), PublicAccess, NoAuthorization(reason: 'a test endpoint')]
    public function adminPing(): FeedData
    {
        return new FeedData('admin');
    }

    #[Get('/api/me', name: 'account.me'), Domain('{account}.{root}'), PublicAccess, NoAuthorization(reason: 'a test endpoint')]
    public function accountMe(string $account): FeedData
    {
        return new FeedData('account:' . $account);
    }

    #[Get('/api/hook', name: 'other.hook'), Domain('{other}'), WithoutSession(reason: 'a feed fetched by machines'), PublicAccess, NoAuthorization(reason: 'a test endpoint')]
    public function otherHook(): FeedData
    {
        return new FeedData('other');
    }
}

// ------------------------------------------------------------ channels

#[Channel('feed', message: FeedData::class), PublicAccess, NoAuthorization(reason: 'everyone\'s')]
final readonly class Feed {}

#[Channel('admin.feed', message: FeedData::class), Domain('admin.{root}'), Panel('staff'), PublicAccess, NoAuthorization(reason: 'everyone\'s there')]
final readonly class AdminFeed {}

#[Channel('account.feed', message: FeedData::class), Domain('{account}.{root}'), PublicAccess, NoAuthorization(reason: 'everyone\'s there')]
final readonly class AccountFeed {}

// ------------------------------------------------------------- fixture

/** @var list<class-string> */
const ROUTES = [
    ShopLayout::class, AdminLayout::class, AdminOrdersLayout::class, AccountLayout::class, OtherLayout::class,
    ShopHome::class, ShopOrders::class, AdminHome::class, AdminOrders::class, AdminOrder::class,
    AccountHome::class, AccountInvoice::class, OtherHome::class, AdminReports::class, AccountReports::class,
    ShopErrorPage::class, AdminErrorPage::class,
];

function domains(?TrustedProxies $proxies = null, ?int $port = null, string $scheme = 'https'): Domains
{
    return new Domains(['root' => PRIMARY, 'other' => OTHER], scheme: $scheme, port: $port, proxies: $proxies);
}

final class App
{
    public readonly Kernel $kernel;
    public readonly RouteRegistry $routes;
    public readonly LiveUpdates $live;

    /** @var list<string> what the Kernel logged: what a 500 was, for the assertion that met it */
    public static array $failures = [];

    public function __construct(?Domains $domains = null)
    {
        $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
        $container = new Container();
        $container->instance(Orm::class, $orm);
        $this->routes = new RouteRegistry();
        $this->routes->registerAll(ROUTES);
        $actions = new ActionRegistry($container->resolver());
        $actions->registerAll([AnyActions::class, AdminActions::class, AccountActions::class]);
        $queries = new QueryRegistry($container->resolver());
        foreach ([AnyCount::class, AdminRevenue::class, AccountBalance::class] as $query) {
            $queries->register($query);
        }
        $endpoints = new EndpointRegistry();
        $endpoints->registerAll([Endpoints::class]);
        $channels = new ChannelRegistry();
        $channels->registerAll([Feed::class, AdminFeed::class, AccountFeed::class]);
        $sessionId = static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '';
        $this->live = LiveUpdates::fromKey(KEY, new MemoryHub(), $sessionId);
        $panels = new PanelContext();

        $this->kernel = new Kernel(
            orm: $orm,
            routes: $this->routes,
            queries: $queries,
            actions: $actions,
            auth: new AuthContext(),
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => ['any.count', 'admin.revenue', 'account.balance'],
            log: static function (\Throwable $e): void {
                App::$failures[] = $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
            },
            gate: new AllowAllGate(),
            builds: new BuildRegistry('b1'),
            container: $container,
            panels: $panels,
            panelRegistry: new PanelRegistry(new PanelDefinition('staff', domain: 'admin.{root}')),
            fragments: new FragmentSupport(
                signer: new Signer(KEY, FragmentToken::PURPOSE),
                builds: new BuildRegistry('b1'),
                render: static fn (): Response => Response::make(200, 'fragment'),
                sessionId: $sessionId,
                panels: $panels,
                orm: $orm,
                renderRoute: static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'payload:' . $l->chain->page->name),
                routeSigner: new Signer(KEY, RouteToken::PURPOSE),
            ),
            manifest: new FragmentManifest([
                'admin.orders' => [['id' => 'b1', 'component' => 'Stats', 'params' => []]],
                'account.home' => [['id' => 'b1', 'component' => 'Balance', 'params' => []]],
                'shop.home' => [['id' => 'b1', 'component' => 'Hero', 'params' => []]],
            ]),
            live: $this->live,
            endpoints: $endpoints,
            channels: $channels,
            domains: $domains ?? domains(),
        );
    }

    /**
     * Sends a request as a browser on $host would, signed in to the session.
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, ?string $host, string $path, string $body = '', array $headers = [], ?string $remote = null): Response
    {
        return $this->kernel->handle(
            new Request($method, $path, [
                ...($host === null ? [] : ['host' => $host]),
                'x-xsrf-token' => 'tok',
                'content-type' => 'application/json',
                ...$headers,
            ], self::query($path), cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 'tok'], remoteAddress: $remote),
            $this->respond(...),
            $body,
        );
    }

    /** A page answered as JSON: which page, its data, its layouts, and what the page would link to. */
    private function respond(LoadedRoute $loaded, Request $request): Response
    {
        $hrefs = [];
        foreach (['shop.home' => [], 'admin.orders' => [], 'admin.order' => ['order' => '9'], 'account.home' => ['account' => 'acme'],
            'account.invoice' => ['account' => 'globex', 'invoice' => 'i-1'], 'other.home' => []] as $name => $parameters) {
            $hrefs[$name] = $this->routes->href($name, $parameters, from: $request);
        }

        return Response::make(200, json_encode([
            'page' => $loaded->chain->page->name ?? $loaded->chain->page->view,
            'data' => $loaded->page === null ? null : get_object_vars($loaded->page),
            'layouts' => array_map(static fn (Data $d): array => get_object_vars($d), array_values($loaded->layouts)),
            'params' => $loaded->parameters,
            'host' => HostMatch::of($request)?->host,
            'hrefs' => $hrefs,
        ], JSON_THROW_ON_ERROR), ['content-type' => 'application/json']);
    }

    /** @return array<string, string> */
    private static function query(string $path): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $name => $value) {
            if (\is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}

/** @return array<string, mixed> */
function page(Response $response): array
{
    expect($response->status)->toBe(200, $response->body() . ' ' . implode("\n", App::$failures));

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, string> $params */
function claims(string $route, string $host, array $params = [], string $panel = ''): TokenClaims
{
    $signer = new Signer(KEY, RouteToken::PURPOSE);

    return new TokenClaims(
        route: $route,
        params: $params,
        panel: $panel,
        tenant: \Tinvar\Render\TenantClaim::NONE,
        build: 'b1',
        sessionHash: TokenClaims::hashSession($signer, 'session-a'),
        expiresAt: time() + 600,
        issuedAt: time(),
        authHash: TokenClaims::hashAuth($signer, null, ''),
        host: $host,
    );
}

// =============================================================== pages

describe('pages and layouts', function () {
    it('serves each host its own page at one path, inside its own layouts alone', function () {
        $app = new App();

        expect(page($app->send('GET', PRIMARY, '/')))->toMatchArray(['page' => 'shop.home', 'layouts' => [['layout' => 'shop']]])
            ->and(page($app->send('GET', ADMIN, '/')))->toMatchArray(['page' => 'admin.home', 'layouts' => [['layout' => 'admin']]])
            ->and(page($app->send('GET', ACME, '/')))->toMatchArray(['page' => 'account.home', 'data' => ['page' => 'account.home', 'account' => 'acme'], 'layouts' => [['layout' => 'account:acme']]])
            ->and(page($app->send('GET', GLOBEX, '/'))['data'])->toBe(['page' => 'account.home', 'account' => 'globex'])
            ->and(page($app->send('GET', OTHER, '/')))->toMatchArray(['page' => 'other.home', 'layouts' => [['layout' => 'other']]]);
    });

    it('nests a host\'s layouts under one another, and never another host\'s', function () {
        $app = new App();

        expect(page($app->send('GET', ADMIN, '/orders/9'))['layouts'])->toBe([['layout' => 'admin'], ['layout' => 'admin-orders']])
            ->and(page($app->send('GET', PRIMARY, '/orders'))['layouts'])->toBe([['layout' => 'shop']])
            ->and(page($app->send('GET', ADMIN, '/orders'))['layouts'])->toBe([['layout' => 'admin'], ['layout' => 'admin-orders']]);
    });

    it('answers a page of one host on another served host with a 404, an unserved host with a 421, and no host with a 400', function (string $host, string $path, int $status) {
        expect((new App())->send('GET', $host === 'none' ? null : $host, $path)->status)->toBe($status);
    })->with([
        'an admin page on the primary host' => [PRIMARY, '/orders/9', 404],
        'an account page on the primary host' => [PRIMARY, '/invoices/i-1', 404],
        'an account page on the admin host' => [ADMIN, '/invoices/i-1', 404],
        'a storefront page on the configured domain' => [OTHER, '/orders', 404],
        'an admin page on an account host' => [ACME, '/orders/9', 404],
        'a page on an unserved host' => ['evil.test', '/', 421],
        'a page two labels deep under a parameter' => ['a.b.example.test', '/', 421],
        'a page on the configured domain\'s own subdomain' => ['admin.shop.other.test', '/', 421],
        'a page naming no host' => ['none', '/', 400],
    ]);

    it('binds a host parameter before the path\'s, and hands both to the loader', function () {
        expect(page((new App())->send('GET', ACME, '/invoices/i-7')))->toMatchArray([
            'page' => 'account.invoice',
            'data' => ['page' => 'account.invoice:i-7', 'account' => 'acme'],
            'params' => ['account' => 'acme', 'invoice' => 'i-7'],
        ]);
    });

    it('puts a section on a host through an abstract base class, and lets one page of it say otherwise', function () {
        $app = new App();

        expect(page($app->send('GET', ADMIN, '/reports'))['page'])->toBe('admin.reports')
            ->and(page($app->send('GET', ACME, '/reports'))['data'])->toBe(['page' => 'account.reports', 'account' => 'acme'])
            ->and($app->send('GET', PRIMARY, '/reports')->status)->toBe(404);
    });

    it('asks the most specific host first: the fixed admin host before the parameter\'s', function () {
        // admin.example.test matches {account}.example.test too; it is the admin host.
        expect(page((new App())->send('GET', ADMIN, '/'))['page'])->toBe('admin.home');
    });
});

// ======================================================== host spellings

describe('the host a request names', function () {
    it('is read lowercase and without a trailing dot', function (string $host, string $page) {
        expect(page((new App())->send('GET', $host, '/'))['page'])->toBe($page);
    })->with([
        'uppercase' => ['ADMIN.Example.TEST', 'admin.home'],
        'a trailing dot' => ['admin.example.test.', 'admin.home'],
        'an uppercase parameter' => ['ACME.example.test', 'account.home'],
        'the configured domain with a trailing dot' => ['shop.other.test.', 'other.home'],
    ]);

    it('hands a parameter its lowercase label', function () {
        expect(page((new App())->send('GET', 'ACME.Example.Test.', '/'))['data'])->toBe(['page' => 'account.home', 'account' => 'acme']);
    });

    it('answers the default port, refuses another, and refuses a doubled one', function (string $host, int $status) {
        expect((new App())->send('GET', $host, '/')->status)->toBe($status);
    })->with([
        'the default port' => [ADMIN . ':443', 200],
        'another port' => [ADMIN . ':8443', 421],
        'a doubled port' => [ADMIN . ':443:443', 421],
        'an empty port' => [ADMIN . ':', 421],
    ]);

    it('answers a dev port it was configured with, and writes it into every link to another host', function () {
        $app = new App(domains(port: 8080, scheme: 'http'));

        // A Host naming no port names the port the request came on, which
        // only the server knows: it is answered (ADR 0149).
        expect($app->send('GET', ADMIN . ':80', '/')->status)->toBe(421)
            ->and($app->send('GET', ADMIN . ':8081', '/')->status)->toBe(421)
            ->and(page($app->send('GET', ADMIN . ':8080', '/'))['hrefs']['shop.home'])->toBe('http://example.test:8080/')
            ->and(page($app->send('GET', PRIMARY . ':8080', '/'))['hrefs']['account.home'])->toBe('http://acme.example.test:8080/');
    });

    it('takes an IDN host as its punycode, and refuses one spelt in Unicode', function () {
        $app = new App();

        expect(page($app->send('GET', 'xn--mnchen-3ya.example.test', '/'))['data'])->toBe(['page' => 'account.home', 'account' => 'xn--mnchen-3ya'])
            ->and($app->send('GET', 'münchen.example.test', '/')->status)->toBe(421);
    });

    it('believes X-Forwarded-Host only from a pinned proxy sending the proxy secret', function () {
        $secret = str_repeat('s', 32);
        $app = new App(domains(TrustedProxies::of(['10.0.0.0/8'], own: [], secret: $secret)));
        $forwarded = ['x-forwarded-host' => ADMIN, 'x-forwarded-for' => '203.0.113.9'];

        expect(page($app->send('GET', 'proxy.internal', '/', headers: [...$forwarded, TrustedProxies::SECRET_HEADER => $secret], remote: '10.0.0.5'))['page'])->toBe('admin.home')
            // From the range without the secret: the proxy's own Host, unserved.
            ->and($app->send('GET', 'proxy.internal', '/', headers: $forwarded, remote: '10.0.0.5')->status)->toBe(421)
            // From anywhere else: its Host, whatever it forwarded.
            ->and(page($app->send('GET', PRIMARY, '/', headers: [...$forwarded, TrustedProxies::SECRET_HEADER => $secret], remote: '198.51.100.7'))['page'])->toBe('shop.home');
    });
});

// =============================================================== links

describe('links', function () {
    it('writes a path to a page on the host being drawn and an absolute URL to one on another, from every host', function () {
        $app = new App();

        expect(page($app->send('GET', PRIMARY, '/'))['hrefs'])->toBe([
            'shop.home' => '/',
            'admin.orders' => 'https://admin.example.test/orders',
            'admin.order' => 'https://admin.example.test/orders/9',
            'account.home' => 'https://acme.example.test/',
            'account.invoice' => 'https://globex.example.test/invoices/i-1',
            'other.home' => 'https://shop.other.test/',
        ])->and(page($app->send('GET', ADMIN, '/'))['hrefs'])->toBe([
            'shop.home' => 'https://example.test/',
            'admin.orders' => '/orders',
            'admin.order' => '/orders/9',
            'account.home' => 'https://acme.example.test/',
            'account.invoice' => 'https://globex.example.test/invoices/i-1',
            'other.home' => 'https://shop.other.test/',
        ])->and(page($app->send('GET', ACME, '/'))['hrefs'])->toBe([
            'shop.home' => 'https://example.test/',
            'admin.orders' => 'https://admin.example.test/orders',
            'admin.order' => 'https://admin.example.test/orders/9',
            // Acme's own host: a path. Globex's: another host, absolute.
            'account.home' => '/',
            'account.invoice' => 'https://globex.example.test/invoices/i-1',
            'other.home' => 'https://shop.other.test/',
        ])->and(page($app->send('GET', OTHER, '/'))['hrefs'])->toBe([
            'shop.home' => 'https://example.test/',
            'admin.orders' => 'https://admin.example.test/orders',
            'admin.order' => 'https://admin.example.test/orders/9',
            'account.home' => 'https://acme.example.test/',
            'account.invoice' => 'https://globex.example.test/invoices/i-1',
            'other.home' => '/',
        ]);
    });

    it('tells a storefront page no panel\'s own domain, and every page the hosts it links to', function () {
        $app = new App();
        $told = static function (App $app, string $host, string $path = '/'): array {
            $response = $app->kernel->handle(new Request('GET', $path, ['host' => $host]), static fn (LoadedRoute $l, Request $r): Response => Response::make(200, json_encode($app->kernel->hostsFor($r, $l), JSON_THROW_ON_ERROR)));

            return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        };

        expect($told($app, ACME))->toMatchArray(['scheme' => 'https', 'primary' => '{root}', 'current' => ['pattern' => '{account}.{root}', 'params' => ['account' => 'acme']]])
            ->and($told($app, OTHER)['roots'])->toHaveKeys(['root', 'other'])
            ->and($told($app, ADMIN)['current'])->toBe(['pattern' => 'admin.{root}', 'params' => []]);
    });
});

// ============================================================== tokens

describe('route and fragment tokens', function () {
    it('mints a page\'s route token for the host it was drawn on', function (string $host, string $path) {
        $response = (new App())->send('GET', $host, $path);
        $token = RouteToken::decode((string) $response->header('x-route-token'), new Signer(KEY, RouteToken::PURPOSE));

        expect($token->claims->host)->toBe(strtolower($host));
    })->with([
        'the primary host' => [PRIMARY, '/'],
        'the fixed host' => [ADMIN, '/orders'],
        'a parameter\'s host' => [ACME, '/'],
        'the configured domain' => [OTHER, '/'],
    ]);

    it('re-renders a route token after an action only on the host it was minted on, with its parameters', function () {
        $app = new App();
        $acme = new RouteToken(claims('account.home', ACME, ['account' => 'acme']))->encode(new Signer(KEY, RouteToken::PURPOSE));
        $body = json_encode(['_routes' => [$acme]], JSON_THROW_ON_ERROR);
        $payloads = static fn (Response $r): array => json_decode($r->body(), true)['payloads'] ?? [];

        expect($payloads($app->send('POST', ACME, '/_action/account.touch', $body)))->not->toBe([])
            // The same pattern, another account: not the page the token names.
            ->and($payloads($app->send('POST', GLOBEX, '/_action/account.touch', $body)))->toBe([])
            ->and($payloads($app->send('POST', ACME, '/_action/any.touch', $body)))->not->toBe([])
            ->and($payloads($app->send('POST', PRIMARY, '/_action/any.touch', $body)))->toBe([]);
    });

    it('opens a fragment only on the host its token was minted on', function () {
        $app = new App();
        $signer = new Signer(KEY, FragmentToken::PURPOSE);
        $page = page($app->send('GET', ACME, '/'));
        expect($page['page'])->toBe('account.home');

        $fragment = static fn (string $host): string => new FragmentToken(
            component: 'Balance',
            props: [],
            route: 'account.home',
            params: ['account' => 'acme'],
            panel: '',
            tenant: \Tinvar\Render\TenantClaim::NONE,
            build: 'b1',
            sessionHash: TokenClaims::hashSession($signer, 'session-a'),
            expiresAt: time() + 600,
            issuedAt: time(),
            authHash: TokenClaims::hashAuth($signer, null, ''),
            host: $host,
        )->encode($signer);
        $acme = $fragment(ACME);

        expect($app->send('GET', ACME, "/_fragment/{$acme}")->status)->toBe(200)
            ->and($app->send('GET', GLOBEX, "/_fragment/{$acme}")->status)->toBe(404)
            ->and($app->send('GET', PRIMARY, "/_fragment/{$acme}")->status)->toBe(404)
            // Minted for globex's host, naming acme's page: no page there.
            ->and($app->send('GET', GLOBEX, '/_fragment/' . $fragment(GLOBEX))->status)->toBe(404)
            // Minted for no host, where the application routes by host.
            ->and($app->send('GET', ACME, '/_fragment/' . $fragment(''))->status)->toBe(404);
    });
});

// ============================================================= actions

describe('actions', function () {
    it('runs an action held to a host on that host alone, and one holding none on every host', function (string $action, string $host, int $status) {
        expect((new App())->send('POST', $host, "/_action/{$action}", '{}')->status)->toBe($status);
    })->with([
        'admin on admin' => ['admin.touch', ADMIN, 200],
        'admin on primary' => ['admin.touch', PRIMARY, 404],
        'admin on an account' => ['admin.touch', ACME, 404],
        'account on acme' => ['account.touch', ACME, 200],
        'account on globex' => ['account.touch', GLOBEX, 200],
        'account on admin' => ['account.touch', ADMIN, 404],
        'account on primary' => ['account.touch', PRIMARY, 404],
        'any on primary' => ['any.touch', PRIMARY, 200],
        'any on admin' => ['any.touch', ADMIN, 200],
        'any on acme' => ['any.touch', ACME, 200],
        'any on the configured domain' => ['any.touch', OTHER, 200],
        'any on an unserved host' => ['any.touch', 'evil.test', 421],
    ]);

    it('answers a form post and a Precognition request on the action\'s host alone', function () {
        $app = new App();
        $form = ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html'];
        $precog = ['precognition' => 'true'];

        expect($app->send('POST', ADMIN, '/_action/admin.touch', '_token=tok', $form)->status)->not->toBe(404)
            ->and($app->send('POST', PRIMARY, '/_action/admin.touch', '_token=tok', $form)->status)->toBe(404)
            ->and($app->send('POST', ADMIN, '/_action/admin.touch', '{}', $precog)->status)->not->toBe(404)
            ->and($app->send('POST', PRIMARY, '/_action/admin.touch', '{}', $precog)->status)->toBe(404);
    });

    it('redirects within its own origin, to a fixed host absolutely, and to a parameter\'s host only when opted in', function () {
        $app = new App();
        $redirect = static fn (Response $r): ?string => $r->header('x-redirect') ?? $r->header('location');

        Redirects::$location = $app->routes->href('admin.orders', [], from: new Request('GET', '/', ['host' => PRIMARY])->withAttribute(HostMatch::ATTRIBUTE, new HostMatch(null, PRIMARY)));
        expect(Redirects::$location)->toBe('https://admin.example.test/orders')
            ->and($redirect($app->send('POST', PRIMARY, '/_action/any.go', '{}')))->toBe('https://admin.example.test/orders');

        Redirects::$location = 'https://acme.example.test/';
        expect($app->send('POST', PRIMARY, '/_action/any.go', '{}')->status)->toBe(500);

        Redirects::$location = 'https://evil.test/';
        expect($app->send('POST', PRIMARY, '/_action/any.go', '{}')->status)->toBe(500);

        // redirect() and back() stay on the origin the action was called on.
        expect($redirect($app->send('POST', ADMIN, '/_action/any.redirect', '{}')))->toBe('/orders')
            ->and($redirect($app->send('POST', ADMIN, '/_action/any.back', '{}', ['referer' => 'https://admin.example.test/orders/9'])))->toBe('/orders/9')
            ->and($redirect($app->send('POST', ADMIN, '/_action/any.back', '{}', ['referer' => 'https://example.test/orders'])))->not->toContain('example.test/orders');
    });
});

// ============================================================= queries

describe('queries', function () {
    it('answers a query held to a host over HTTP on that host alone', function (string $query, string $host, int $status) {
        expect((new App())->send('POST', $host, "/_query/{$query}", '{"args":{}}', ['x-route' => 'any'])->status)->toBe($status);
    })->with([
        'admin on admin' => ['admin.revenue', ADMIN, 200],
        'admin on primary' => ['admin.revenue', PRIMARY, 404],
        'account on acme' => ['account.balance', ACME, 200],
        'account on admin' => ['account.balance', ADMIN, 404],
        'any on the configured domain' => ['any.count', OTHER, 200],
        'any on an unserved host' => ['any.count', 'evil.test', 421],
    ]);
});

// =========================================================== endpoints

describe('endpoints', function () {
    it('answers each host\'s own endpoint at one path, and hands a host parameter to it', function (string $host, string $path, int $status, ?string $text) {
        $response = (new App())->send('GET', $host, $path, headers: ['accept' => 'application/json']);

        expect($response->status)->toBe($status, $response->body());
        if ($text !== null) {
            expect(json_decode($response->body(), true)['text'] ?? null)->toBe($text);
        }
    })->with([
        'the storefront\'s ping' => [PRIMARY, '/api/ping', 200, 'shop'],
        'the admin host\'s ping' => [ADMIN, '/api/ping', 200, 'admin'],
        'acme\'s me' => [ACME, '/api/me', 200, 'account:acme'],
        'globex\'s me' => [GLOBEX, '/api/me', 200, 'account:globex'],
        'me on the primary host' => [PRIMARY, '/api/me', 404, null],
        'a sessionless endpoint on its host' => [OTHER, '/api/hook', 200, 'other'],
        'a sessionless endpoint on another' => [PRIMARY, '/api/hook', 404, null],
        'a ping on the configured domain' => [OTHER, '/api/ping', 404, null],
        'a ping on an unserved host' => ['evil.test', '/api/ping', 421, null],
    ]);
});

// ============================================================ channels

describe('channels and live updates', function () {
    it('subscribes to a channel held to a host on that host alone, and trades its token there alone', function () {
        $app = new App();
        $subscribe = static fn (string $host, string $channel): Response => $app->send('POST', $host, "/_channel/{$channel}", json_encode(['params' => new \stdClass()], JSON_THROW_ON_ERROR));

        $admin = $subscribe(ADMIN, 'admin.feed');
        expect($admin->status)->toBe(200, $admin->body())
            ->and($subscribe(PRIMARY, 'admin.feed')->status)->toBe(404)
            ->and($subscribe(ACME, 'account.feed')->status)->toBe(200)
            ->and($subscribe(ADMIN, 'account.feed')->status)->toBe(404)
            ->and($subscribe(OTHER, 'feed')->status)->toBe(200);

        $token = json_decode($admin->body(), true)['token'];
        $trade = static fn (string $host): array => json_decode($app->send('POST', $host, SubscribeEndpoint::PATH, json_encode(['tokens' => [$token]], JSON_THROW_ON_ERROR))->body(), true) ?? [];

        expect($trade(ADMIN))->not->toBe([])
            ->and(json_encode($trade(PRIMARY)))->not->toContain('admin.feed');
    });

    it('pins a channel on a parameter\'s host to the very host it was subscribed on', function () {
        $app = new App();
        $acme = json_decode($app->send('POST', ACME, '/_channel/account.feed', '{"params":{}}')->body(), true)['token'] ?? '';
        $trade = static fn (string $host): Response => $app->send('POST', $host, SubscribeEndpoint::PATH, json_encode(['tokens' => [$acme]], JSON_THROW_ON_ERROR));

        $here = json_decode($trade(ACME)->body(), true);
        $there = json_decode($trade(GLOBEX)->body(), true);

        expect($acme)->not->toBe('')
            ->and(json_encode($here))->not->toBe(json_encode($there));
    });
});

// ============================================================== errors

describe('error pages and the 404', function () {
    it('shows each host its own error page, and the application\'s on a host with none', function (string $host, string $path, string $page) {
        $response = (new App())->send('GET', $host, $path, headers: ['accept' => 'text/html']);

        expect($response->status)->toBe(404)
            ->and(json_decode($response->body(), true)['page'] ?? null)->toBe($page);
    })->with([
        'the primary host' => [PRIMARY, '/nowhere', 'errors/page'],
        'the admin host' => [ADMIN, '/nowhere', 'admin/error'],
        'an account host, which may have none of its own' => [ACME, '/nowhere', 'errors/page'],
        'the configured domain' => [OTHER, '/nowhere', 'errors/page'],
        'an admin page asked on the primary host' => [PRIMARY, '/orders/9', 'errors/page'],
    ]);

    it('shows no host\'s error page to a host nobody serves, nor to a request naming none', function () {
        $app = new App();
        $unserved = $app->send('GET', 'evil.test', '/', headers: ['accept' => 'text/html']);
        $none = $app->send('GET', null, '/', headers: ['accept' => 'text/html']);

        expect($unserved->status)->toBe(421)
            ->and($unserved->body())->not->toContain('errors/page')->and($unserved->body())->not->toContain('admin/error')
            ->and($none->status)->toBe(400)
            ->and($none->body())->not->toContain('errors/page');
    });
});

// ======================================================== worker mode

describe('worker mode', function () {
    it('leaks no host from one request to the next on one Kernel', function () {
        $app = new App();

        $sequence = [];
        foreach ([ADMIN, ACME, PRIMARY, GLOBEX, OTHER, ADMIN, 'evil.test', ACME] as $host) {
            $response = $app->send('GET', $host, '/');
            $sequence[] = $response->status === 200 ? page($response)['data'] : $response->status;
        }

        expect($sequence)->toBe([
            ['page' => 'admin.home', 'account' => ''],
            ['page' => 'account.home', 'account' => 'acme'],
            ['page' => 'shop.home', 'account' => ''],
            ['page' => 'account.home', 'account' => 'globex'],
            ['page' => 'other.home', 'account' => ''],
            ['page' => 'admin.home', 'account' => ''],
            421,
            ['page' => 'account.home', 'account' => 'acme'],
        ]);
    });

    it('holds an action and a query to the host of the request in hand, not the one before', function () {
        $app = new App();

        expect($app->send('POST', ADMIN, '/_action/admin.touch', '{}')->status)->toBe(200)
            ->and($app->send('POST', PRIMARY, '/_action/admin.touch', '{}')->status)->toBe(404)
            ->and($app->send('POST', ACME, '/_query/account.balance', '{"args":{}}', ['x-route' => 'any'])->status)->toBe(200)
            ->and($app->send('POST', ADMIN, '/_query/account.balance', '{"args":{}}', ['x-route' => 'any'])->status)->toBe(404);
    });
});

// ============================================== mail links, static files

describe('links built where there is no request', function () {
    // A mail, a job, a command: no host is being drawn, so every link is
    // absolute, on the host of the route it opens.
    it('writes every route absolute, on its own host with its parameters', function () {
        $routes = (new App())->routes;

        expect($routes->href('shop.orders'))->toBe('https://example.test/orders')
            ->and($routes->href('admin.order', ['order' => '9']))->toBe('https://admin.example.test/orders/9')
            ->and($routes->href('account.invoice', ['account' => 'globex', 'invoice' => 'i-1']))->toBe('https://globex.example.test/invoices/i-1')
            ->and($routes->href('other.home'))->toBe('https://shop.other.test/');
    });
});

describe('static files', function () {
    // A built page is the primary host's (ADR 0149): the front controller
    // sends one on the primary host alone, never the admin host's `/`.
    it('sends a built page on the primary host alone', function (string $host, bool $sent) {
        $root = sys_get_temp_dir() . '/tinvar-matrix-static-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents("{$root}/index.html", '<p>built</p>');

        try {
            $file = \Tinvar\Http\StaticFiles::match($root, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $host], [], SESSION_COOKIE, domains());
            expect($file !== null)->toBe($sent);
        } finally {
            unlink("{$root}/index.html");
            rmdir($root);
        }
    })->with([
        'the primary host' => [PRIMARY, true],
        'the primary host, uppercase with a trailing dot' => ['EXAMPLE.test.', true],
        'the fixed host' => [ADMIN, false],
        'a parameter\'s host' => [ACME, false],
        'the configured domain' => [OTHER, false],
        'an unserved host' => ['evil.test', false],
    ]);
});
