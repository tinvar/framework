<?php

declare(strict_types=1);

/**
 * Pages matched on host and path (ADR 0149): the same path on two hosts,
 * layouts that wrap only their own host's pages, host parameters bound as
 * path parameters are, the overlaps registration refuses, a token's route
 * refused on another host, and links built for the host they point at.
 */

namespace Tinvar\Tests\Routing\HostRouting;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\Data;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Site;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

enum Region: string
{
    case Eu = 'eu';
    case Us = 'us';
}

final readonly class TextData extends Data
{
    public function __construct(public string $text) {}
}

#[Page('/orders', view: 'shop/orders', name: 'shop.orders')]
final class ShopOrders
{
    public function __invoke(): TextData
    {
        return new TextData('shop');
    }
}

#[Page('/orders', view: 'admin/orders', name: 'admin.orders'), Domain('admin.{root}')]
final class AdminOrders
{
    public function __invoke(): TextData
    {
        return new TextData('admin');
    }
}

#[Layout('/', view: 'shop/layout')]
final class ShopLayout {}

#[Layout('/', view: 'admin/layout'), Domain('admin.{root}')]
final class AdminLayout {}

#[Page('/dashboard', view: 'accounts/dashboard', name: 'accounts.dashboard'), Domain('{account}.{root}')]
final class AccountDashboard
{
    public function __invoke(string $account): TextData
    {
        return new TextData("account {$account}");
    }
}

#[Page('/stock/{sku}', view: 'regions/stock', name: 'regions.stock'), Domain('{region}.stock.example.com')]
final class RegionStock
{
    public function __invoke(Region $region, int $sku): TextData
    {
        return new TextData("{$region->name} {$sku}");
    }
}

#[Page('/', view: 'sites/home', name: 'sites.home'), Domain('{site}.sites.{root}'), Authorize('view', 'site')]
final class SiteHome
{
    public function __invoke(Site $site): TextData
    {
        return new TextData($site->code);
    }
}

#[Page('/again', view: 'accounts/again'), Domain('{acct}.{root}')]
final class OtherAccountSpelling {}

#[Page('/dashboard', view: 'accounts/twin'), Domain('{other}.{root}')]
final class TwinDashboard {}

#[Page('/x/{account}', view: 'accounts/clash'), Domain('{account}.{root}')]
final class ParameterTwice {}

#[Page('/bad', view: 'bad/page'), Domain('Admin.{root}')]
final class Malformed {}

#[Domain('admin.{root}')]
abstract class AdminSection {}

#[Page('/staff', view: 'admin/staff', name: 'admin.staff')]
final class Staff extends AdminSection {}

#[Page('/status', view: 'admin/status', name: 'admin.status'), Domain('status.example.org')]
final class Status extends AdminSection {}

const PAGES = [ShopOrders::class, AdminOrders::class, ShopLayout::class, AdminLayout::class, AccountDashboard::class, RegionStock::class, SiteHome::class, Staff::class, Status::class];

function domains(RouteRegistry $routes): Domains
{
    return new Domains(['root' => 'shop.test'])->serving($routes->hostPatterns());
}

function routes(): RouteRegistry
{
    $routes = new RouteRegistry();
    $routes->registerAll(PAGES);
    $routes->serveOn(domains($routes));

    return $routes;
}

function at(string $host): HostMatch
{
    $routes = routes();

    return $routes->domains()->resolve(new Request('GET', '/', ['host' => $host]));
}

function on(string $host): Request
{
    return new Request('GET', '/', ['host' => $host]);
}

function serve(string $host, string $path, ?Fixture $fixture = null): string
{
    $fixture ??= new Fixture(racks: 0, unitsPerRack: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $router = new Router(routes(), new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate()), static fn (string $c): Middleware => new $c());

    return $router->handle(
        new Request('GET', $path, ['host' => $host]),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, $l->page instanceof TextData ? $l->page->text : ''),
    )->body();
}

it('matches the same path to a different page on each host', function () {
    expect(serve('shop.test', '/orders'))->toBe('shop')
        ->and(serve('admin.shop.test', '/orders'))->toBe('admin')
        ->and(routes()->match('/orders', at('admin.shop.test'))?->page->class)->toBe(AdminOrders::class)
        ->and(routes()->match('/orders', at('shop.test'))?->page->class)->toBe(ShopOrders::class);
});

it('finds no page of one host on another', function () {
    expect(routes()->match('/staff', at('shop.test')))->toBeNull()
        ->and(routes()->match('/dashboard', at('admin.shop.test')))->toBeNull();
});

it('refuses a host nobody listed before any page is matched', function () {
    serve('evil.test', '/orders');
})->throws(HttpException::class);

it('wraps a page only in the layouts of its own host', function () {
    $admin = routes()->match('/orders', at('admin.shop.test'));
    $shop = routes()->match('/orders', at('shop.test'));

    expect(array_map(static fn ($l) => $l->class, $admin->layouts))->toBe([AdminLayout::class])
        ->and(array_map(static fn ($l) => $l->class, $shop->layouts))->toBe([ShopLayout::class]);
});

it('binds a host parameter as a string, an enum and a model, as a path parameter is bound', function () {
    $fixture = new Fixture(racks: 1, unitsPerRack: 0);
    $site = $fixture->connection->select('SELECT id, code FROM sites LIMIT 1')[0];

    expect(serve('acme.shop.test', '/dashboard'))->toBe('account acme')
        ->and(serve('eu.stock.example.com', '/stock/42'))->toBe('Eu 42')
        ->and(serve("{$site['id']}.sites.shop.test", '/', $fixture))->toBe($site['code']);
});

it('answers a host parameter outside its enum with a 404', function () {
    serve('mars.stock.example.com', '/stock/1');
})->throws(NotFoundException::class);

it('puts a section on a host through a base class, and lets one page say otherwise', function () {
    $routes = routes();

    expect($routes->named('admin.staff')->domain())->toBe('admin.{root}')
        ->and($routes->named('admin.status')->domain())->toBe('status.example.org');
});

it('refuses two pages of one host and one path, however their parameters are spelt', function () {
    new RouteRegistry()->registerAll([AccountDashboard::class, TwinDashboard::class]);
})->throws(RoutingException::class, 'Two routes claim');

it('refuses a parameter taken from both the host and the path', function () {
    new RouteRegistry()->register(ParameterTwice::class);
})->throws(RoutingException::class, 'from both its host');

it('refuses a malformed host pattern at registration', function () {
    new RouteRegistry()->register(Malformed::class);
})->throws(RoutingException::class, 'not a host name');

it('refuses, once the hosts are known, two spellings of one host', function () {
    $routes = new RouteRegistry();
    $routes->registerAll([AccountDashboard::class, OtherAccountSpelling::class]);

    domains($routes);
})->throws(\InvalidArgumentException::class, 'match the same hosts');

it('re-renders a token\'s route only on the host it was minted on', function () {
    $routes = routes();

    expect($routes->chainFor('admin.orders', [], on('admin.shop.test'))->page->class)->toBe(AdminOrders::class)
        ->and(fn () => $routes->chainFor('admin.orders', [], on('shop.test')))->toThrow(RoutingException::class)
        ->and(fn () => $routes->chainFor('shop.orders', [], on('admin.shop.test')))->toThrow(RoutingException::class)
        // Minted on acme's host, replayed on another tenant's.
        ->and($routes->chainFor('accounts.dashboard', ['account' => 'acme'], on('acme.shop.test'))->parameters)->toBe(['account' => 'acme'])
        ->and(fn () => $routes->chainFor('accounts.dashboard', ['account' => 'acme'], on('globex.shop.test')))->toThrow(RoutingException::class);
});

it('links within a host by path, and to another host by its absolute URL', function () {
    $routes = routes();
    $onShop = new Request('GET', '/')->withAttribute(HostMatch::ATTRIBUTE, at('shop.test'));
    $onAcme = new Request('GET', '/')->withAttribute(HostMatch::ATTRIBUTE, at('acme.shop.test'));

    expect($routes->href('shop.orders', [], $onShop))->toBe('/orders')
        ->and($routes->href('admin.orders', [], $onShop))->toBe('https://admin.shop.test/orders')
        ->and($routes->href('accounts.dashboard', ['account' => 'acme'], $onAcme))->toBe('/dashboard')
        ->and($routes->href('accounts.dashboard', ['account' => 'globex'], $onAcme))->toBe('https://globex.shop.test/dashboard')
        ->and($routes->href('shop.orders', [], $onAcme))->toBe('https://shop.test/orders')
        ->and($routes->href('regions.stock', ['region' => 'us', 'sku' => 7]))->toBe('https://us.stock.example.com/stock/7');
});

it('routes on no host at all where the application configures none', function () {
    $routes = new RouteRegistry();
    $routes->registerAll([ShopOrders::class]);

    expect($routes->match('/orders')?->page->class)->toBe(ShopOrders::class)
        ->and($routes->href('shop.orders'))->toBe('/orders');
});

it('reports in tinvar check a host nobody serves, and two spellings of one host', function () {
    $none = \Tinvar\Console\AppConsole::hostViolations([AdminOrders::class], [], [], null);
    $twice = \Tinvar\Console\AppConsole::hostViolations([AccountDashboard::class, OtherAccountSpelling::class], [], [], new Domains(['root' => 'shop.test']));
    $unconfigured = \Tinvar\Console\AppConsole::hostViolations([AdminOrders::class], [], [], new Domains(['admin' => 'admin.test'], primary: '{admin}'));
    $fine = \Tinvar\Console\AppConsole::hostViolations(PAGES, [], [], new Domains(['root' => 'shop.test']));

    expect($none)->toHaveCount(1)
        ->and($none[0]->rule)->toBe(\Tinvar\Console\AppConsole::HOSTS_MUST_BE_SERVED)
        ->and((string) $twice[0])->toContain('match the same hosts')
        ->and((string) $unconfigured[0])->toContain("no domain called 'root'")
        ->and($fine)->toBe([]);
});

#[ErrorPage([404, 503], view: 'errors/app'), NoAuthorization(reason: 'an error page')]
final class AppErrors {}

#[ErrorPage([500], view: 'errors/admin'), Domain('admin.{root}'), NoAuthorization(reason: 'an error page')]
final class AdminServerErrors {}

#[ErrorPage([404], view: 'errors/admin-missing'), Domain('admin.{root}'), NoAuthorization(reason: 'an error page')]
final class AdminMissing {}

it('picks an exact-status error page over a catch-all, and a host\'s own over the application\'s at the same', function () {
    $routes = new RouteRegistry();
    $routes->registerAll([AppErrors::class, AdminServerErrors::class, AdminMissing::class]);
    $view = static fn (int $status, ?string $host): ?string => $routes->errorChain($status, $host)?->page->view;

    // The application's exact 503 beats the host's 500, which only catches what no page names.
    expect($view(503, 'admin.{root}'))->toBe('errors/app')
        // The host's catch-all still answers a 5xx no page names exactly.
        ->and($view(502, 'admin.{root}'))->toBe('errors/admin')
        ->and($view(500, 'admin.{root}'))->toBe('errors/admin')
        // At the same specificity, the host's own.
        ->and($view(404, 'admin.{root}'))->toBe('errors/admin-missing')
        // Elsewhere, the application's.
        ->and($view(404, null))->toBe('errors/app')
        ->and($view(503, null))->toBe('errors/app')
        ->and($view(502, null))->toBeNull();
});
