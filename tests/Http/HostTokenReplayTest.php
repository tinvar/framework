<?php

declare(strict_types=1);

/**
 * Every token a page mints is its host's (ADR 0149), through the Kernel: a
 * route token re-rendered after an action, a fragment token, a live token
 * traded at /_live/subscribe and a static page's bootstrap, each naming a
 * route on admin.shop.test and sent to shop.test -- by a session that is
 * valid there -- finds nothing. And a host the application does not serve
 * is refused before any of it runs.
 */

namespace Tinvar\Tests\Http\HostTokenReplay;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveToken;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\TagsRead;
use Tinvar\Observability\Probes;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TenantClaim;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\Domains;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

const KEY = 'host-token-replay-test-application-key-0123';
const SESSION_COOKIE = '__Host-test_session';
const ADMIN = 'admin.shop.test';
const SHOP = 'shop.test';

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/orders', view: 'admin/orders', name: 'admin.orders'), Domain('admin.{root}'), NoAuthorization(reason: 'a test page')]
final class AdminOrders
{
    public function __invoke(): TitleData
    {
        return new TitleData('admin');
    }
}

#[Page('/orders', view: 'shop/orders', name: 'shop.orders'), NoAuthorization(reason: 'a test page')]
final class ShopOrders
{
    public function __invoke(): TitleData
    {
        return new TitleData('shop');
    }
}

final class OrderActions
{
    #[Action(name: 'orders.touch'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function touch(): ActionResult
    {
        return ActionResult::revalidate('admin.orders');
    }
}

/** @return array{Kernel, LiveUpdates} */
function kernel(): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'));
    $container = new Container();
    $container->instance(Orm::class, $orm);
    $routes = new RouteRegistry();
    $routes->registerAll([AdminOrders::class, ShopOrders::class]);
    $actions = new ActionRegistry($container->resolver());
    $actions->register(OrderActions::class);
    $sessionId = static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '';
    $live = LiveUpdates::fromKey(KEY, new MemoryHub(), $sessionId);

    return [new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new AllowAllGate(),
        builds: new BuildRegistry('b1'),
        container: $container,
        fragments: new FragmentSupport(
            signer: new Signer(KEY, FragmentToken::PURPOSE),
            builds: new BuildRegistry('b1'),
            render: static fn (): Response => Response::make(200, 'fragment'),
            sessionId: $sessionId,
            renderRoute: static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'payload:' . $l->chain->page->name),
            routeSigner: new Signer(KEY, RouteToken::PURPOSE),
        ),
        manifest: new FragmentManifest(['admin.orders' => [['id' => 'b1', 'component' => 'Stats', 'params' => []]]]),
        live: $live,
        domains: new Domains(['root' => SHOP]),
        probes: new Probes(),
    ), $live];
}

function claims(string $purposeKey, string $host): TokenClaims
{
    $signer = new Signer(KEY, $purposeKey);

    return new TokenClaims(
        route: 'admin.orders',
        params: [],
        panel: '',
        tenant: TenantClaim::NONE,
        build: 'b1',
        sessionHash: TokenClaims::hashSession($signer, 'session-a'),
        expiresAt: time() + 600,
        issuedAt: time(),
        authHash: TokenClaims::hashAuth($signer, null, ''),
        host: $host,
    );
}

/** @param array<string, string> $headers */
function send(Kernel $kernel, string $method, string $host, string $path, string $body = '', array $headers = []): Response
{
    return $kernel->handle(
        new Request($method, $path, ['host' => $host, 'x-xsrf-token' => 'tok', 'content-type' => 'application/json', ...$headers], cookies: [
            SESSION_COOKIE => 'session-a',
            VerifyCsrfToken::COOKIE => 'tok',
        ]),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page:' . $l->chain->page->name),
        $body,
    );
}

it('refuses a host the application does not serve with a 421, before anything runs', function () {
    [$kernel] = kernel();

    expect(send($kernel, 'GET', 'evil.test', '/orders')->status)->toBe(421)
        ->and(send($kernel, 'POST', 'evil.test', '/_action/orders.touch', '{}')->status)->toBe(421)
        ->and(send($kernel, 'GET', SHOP, '/orders')->body())->toBe('page:shop.orders')
        ->and(send($kernel, 'GET', ADMIN, '/orders')->body())->toBe('page:admin.orders');
});

it('refuses a request that names no host with a 400, and still answers a probe that names none', function () {
    [$kernel] = kernel();
    $page = static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page:' . $l->chain->page->name);

    $none = $kernel->handle(new Request('GET', '/orders'), $page);
    $action = $kernel->handle(new Request('POST', '/_action/orders.touch', ['content-type' => 'application/json']), $page, '{}');
    $probe = $kernel->handle(new Request('GET', '/_health', remoteAddress: '127.0.0.1'), $page);

    expect($none->status)->toBe(400)
        ->and($none->body())->not->toContain('page:')
        ->and($action->status)->toBe(400)
        ->and($probe->status)->toBe(200);
});

it('refuses a token minted with no host claim on every host, where the application routes by host', function () {
    [$kernel] = kernel();
    $token = new RouteToken(claims(RouteToken::PURPOSE, ''))->encode(new Signer(KEY, RouteToken::PURPOSE));
    $body = json_encode(['_routes' => [$token]], JSON_THROW_ON_ERROR);

    foreach ([ADMIN, SHOP] as $host) {
        expect(json_decode(send($kernel, 'POST', $host, '/_action/orders.touch', $body)->body(), true)['payloads'] ?? [])->toBe([]);
    }
});

it('re-renders a route token after an action only on the host it was minted on', function () {
    [$kernel] = kernel();
    $token = new RouteToken(claims(RouteToken::PURPOSE, ADMIN))->encode(new Signer(KEY, RouteToken::PURPOSE));
    $body = json_encode(['_routes' => [$token]], JSON_THROW_ON_ERROR);

    $there = json_decode(send($kernel, 'POST', ADMIN, '/_action/orders.touch', $body)->body(), true);
    $here = json_decode(send($kernel, 'POST', SHOP, '/_action/orders.touch', $body)->body(), true);

    expect($there['payloads'])->toBe(['admin.orders?' => 'payload:admin.orders'])
        ->and($here['payloads'] ?? [])->toBe([]);
});

it('refuses a route token whose host claim names another host, even for a route on this one', function () {
    [$kernel] = kernel();
    // A token claiming shop.test for a route that lives on admin: the
    // claim and the route each refuse it.
    $token = new RouteToken(claims(RouteToken::PURPOSE, SHOP))->encode(new Signer(KEY, RouteToken::PURPOSE));

    $body = json_decode(send($kernel, 'POST', ADMIN, '/_action/orders.touch', json_encode(['_routes' => [$token]]))->body(), true);

    expect($body['payloads'] ?? [])->toBe([]);
});

it('renders a fragment token only on the host it was minted on', function () {
    [$kernel] = kernel();
    $claims = claims(FragmentToken::PURPOSE, ADMIN);
    $token = new FragmentToken('Stats', [], $claims->route, [], '', TenantClaim::NONE, 'b1', $claims->sessionHash, $claims->expiresAt, $claims->issuedAt, $claims->authHash, ADMIN)
        ->encode(new Signer(KEY, FragmentToken::PURPOSE));

    expect(send($kernel, 'GET', ADMIN, "/_fragment/{$token}", headers: ['rsc' => '1'])->status)->toBe(200)
        ->and(send($kernel, 'GET', SHOP, "/_fragment/{$token}", headers: ['rsc' => '1'])->status)->toBe(404);
});

it('trades a live token for its topics only on the host it was minted on', function () {
    [$kernel, $live] = kernel();
    $topic = $live->topics->for('', 'orders');
    $token = new LiveToken(claims(LiveToken::PURPOSE, ADMIN), [$topic => [TagsRead::WHOLE]])->encode($live->signer);
    $body = json_encode(['tokens' => [$token]], JSON_THROW_ON_ERROR);

    $there = json_decode(send($kernel, 'POST', ADMIN, '/_live/subscribe', $body)->body(), true);
    $here = json_decode(send($kernel, 'POST', SHOP, '/_live/subscribe', $body)->body(), true);

    expect($there['topics'] ?? [])->toBe([$topic])
        ->and($here['topics'] ?? [])->toBe([]);
});

it('mints a static page\'s fragment tokens only on its own host, each bound to it', function () {
    [$kernel] = kernel();
    $body = json_encode(['route' => 'admin.orders', 'params' => []], JSON_THROW_ON_ERROR);

    $there = json_decode(send($kernel, 'POST', ADMIN, '/_bootstrap', $body)->body(), true);
    $here = json_decode(send($kernel, 'POST', SHOP, '/_bootstrap', $body)->body(), true);

    expect($there['tokens'])->toHaveCount(1)
        ->and(FragmentToken::decode(array_values($there['tokens'])[0], new Signer(KEY, FragmentToken::PURPOSE))->host)->toBe(ADMIN)
        ->and($here['tokens'])->toBe([]);
});
