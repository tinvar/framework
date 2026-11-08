<?php

declare(strict_types=1);

/**
 * What a page is told of the application's hosts (ADR 0149): the domains of
 * the hosts it may link to -- its own, the primary host's, and those of the
 * pages in no panel or in its own -- and no other, so a storefront visitor
 * is never handed the address of a panel on a domain of its own.
 */

namespace Tinvar\Tests\Http\ClientHosts;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\Domains;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

#[Page('/', view: 'shop/home', name: 'shop.home'), NoAuthorization(reason: 'a test page')]
final class Home
{
    public function __invoke(): void {}
}

#[Page('/accounts', view: 'shop/account', name: 'shop.account'), Domain('{account}.{root}'), NoAuthorization(reason: 'a test page')]
final class Account
{
    public function __invoke(string $account): void {}
}

#[Page('/', view: 'portal/home', name: 'portal.home'), Domain('{portal}'), Panel('portal'), NoAuthorization(reason: 'a test page')]
final class PortalHome
{
    public function __invoke(): void {}
}

function kernel(): Kernel
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container();
    $container->instance(Orm::class, $orm);
    $routes = new RouteRegistry();
    $routes->registerAll([Home::class, Account::class, PortalHome::class]);

    return new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry($container->resolver()),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        container: $container,
        panels: new PanelContext(),
        panelRegistry: new PanelRegistry(new PanelDefinition('portal', domain: '{portal}')),
        domains: new Domains(['root' => 'shop.test', 'portal' => 'staff-portal.example.net']),
    );
}

/** @return array<string, mixed> what the page on $host at $path is told */
function told(Kernel $kernel, string $host, string $path = '/'): array
{
    $response = $kernel->handle(
        new Request('GET', $path, ['host' => $host]),
        static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200, json_encode($kernel->hostsFor($passed, $loaded), JSON_THROW_ON_ERROR)),
    );
    expect($response->status)->toBe(200, $response->body());

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

it('tells a storefront page the domains of the hosts it may link to, and not a panel\'s of its own', function () {
    $kernel = kernel();

    expect(told($kernel, 'shop.test')['roots'])->toBe(['root' => 'shop.test'])
        ->and(told($kernel, 'acme.shop.test', '/accounts')['roots'])->toBe(['root' => 'shop.test']);
});

it('tells a panel\'s page its own host\'s domain, and the storefront\'s it links back to', function () {
    expect(told(kernel(), 'staff-portal.example.net')['roots'])
        ->toBe(['root' => 'shop.test', 'portal' => 'staff-portal.example.net']);
});

/**
 * The arguments of each `Frame::render(` call in $source, as written.
 *
 * @return list<string>
 */
function frameRenders(string $source): array
{
    $calls = [];
    $offset = 0;
    while (($at = strpos($source, 'Frame::render(', $offset)) !== false) {
        $depth = 0;
        $start = $at + \strlen('Frame::render');
        for ($i = $start, $n = \strlen($source); $i < $n; $i++) {
            $depth += match ($source[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                break;
            }
        }
        $calls[] = substr($source, $start, $i - $start + 1);
        $offset = $i;
    }

    return $calls;
}

// ADR 0185: the skeleton's Kernel drew its pages with no hosts, so route()
// in a server component wrote a bare path for a page on another host --
// `/sign-in` on the public site for the dashboard host's page, a 404 --
// and every app made from it did the same. Each application this repository
// ships hands every render it draws what hostsFor() says; one routing on no
// host is handed null, as before.
it('hands every page render of each application shipped here the hosts its page may link to', function () {
    $root = \dirname(__DIR__, 4);
    $kernels = [
        'packages/skeleton/app/Kernel.php',
        'examples/shop/app/Kernel.php',
        'examples/docs/app/Kernel.php',
        'packages/tenancy/tests/Browser/app/Fixture.php',
    ];
    foreach ($kernels as $kernel) {
        $renders = frameRenders((string) file_get_contents("{$root}/{$kernel}"));
        expect($renders)->not->toBe([], "{$kernel} draws no page");
        foreach ($renders as $arguments) {
            expect(preg_match('/\bhosts:\s*\$this->http->hostsFor\(\$request, \$loaded\)/', $arguments))->toBe(1, "{$kernel} renders a page without its hosts");
        }
    }
});
