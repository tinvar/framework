<?php

declare(strict_types=1);

/**
 * Live updates through the Kernel: every page render is handed a minter
 * bound to its request, what its loaders read is counted by segment,
 * /_live/subscribe is mounted behind CSRF and the live throttle, and what an
 * action invalidates is published once it is committed.
 */

namespace Tinvar\Tests\Live\LiveKernel;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveToken;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Live\TagsRead;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;

const KEY = 'live-kernel-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Signs in whoever the test says, as an application's session middleware would. */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-test-user');
        self::$auth?->login($user === null ? null : new Member($user), $request->header('x-test-epoch') ?? '');

        return $next($request);
    }
}

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Layout('/shop', view: 'shop/layout'), NoAuthorization(reason: 'a test layout')]
final class ShopLayout
{
    public function __construct(private readonly TagsRead $tags) {}

    public function __invoke(): TitleData
    {
        $this->tags->read('categories');

        return new TitleData('Shop');
    }
}

#[Page('/shop/orders', view: 'shop/orders', name: 'shop.orders'), NoAuthorization(reason: 'a test page')]
final class OrdersPage
{
    public function __construct(private readonly TagsRead $tags) {}

    public function __invoke(): TitleData
    {
        $this->tags->read('orders:customer:42');

        return new TitleData('Orders');
    }
}

final class OrderActions
{
    public function __construct(private readonly Orm $orm) {}

    #[Action(name: 'orders.place'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function place(): ActionResult
    {
        $this->orm->transaction(function (): void {
            $this->orm->connection()->execute('INSERT INTO orders (id) VALUES (1)');
        });

        return ActionResult::revalidateTags('orders', 'orders:customer:42');
    }
}

/** @return array{Kernel, MemoryHub, LiveUpdates} */
function kernel(bool $live = true, ?\Tinvar\Live\Hub $hub = null): array
{
    $connection = PdoConnection::sqlite(':memory:', 'test');
    $connection->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY)');
    $orm = new Orm($connection, new IdentityMap('test'));
    $auth = new AuthContext();
    SignIn::$auth = $auth;
    $container = new Container();
    $container->instance(Orm::class, $orm);

    $routes = new RouteRegistry();
    $routes->registerAll([ShopLayout::class, OrdersPage::class]);
    $actions = new ActionRegistry($container->resolver());
    $actions->register(OrderActions::class);

    $hub ??= new MemoryHub();
    $updates = LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');

    return [new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignIn::class],
        gate: new AllowAllGate(),
        builds: new BuildRegistry('b1'),
        container: $container,
        live: $live ? $updates : null,
    ), $hub, $updates];
}

/**
 * A responder that renders nothing, mints with what a render's queries
 * read, and keeps the minter it was handed.
 *
 * @param list<string> $rendered
 */
function respond(?LiveMinter &$minter, ?string &$token, array $rendered = []): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use (&$minter, &$token, $rendered): Response {
        $handed = $request->attribute(Kernel::LIVE_MINTER);
        $minter = $handed instanceof LiveMinter ? $handed : null;
        $token = $minter?->mint($rendered);

        return Response::make(200, '<html><body></body></html>');
    };
}

/** @param array<string, string> $headers */
function visit(string $path, string $session = 'session-a', array $headers = []): Request
{
    return new Request('GET', $path, $headers, cookies: [SESSION_COOKIE => $session]);
}

describe('a page render', function () {
    it('is handed a minter, and its token names what its loaders and its queries read, by segment', function () {
        [$kernel, , $live] = kernel();

        $kernel->handle(visit('/shop/orders', headers: ['x-test-user' => 'u-1', 'x-test-epoch' => 'e1']), respond($minter, $token, ["\0availability:7"]));

        expect($minter)->toBeInstanceOf(LiveMinter::class);
        $decoded = LiveToken::decode((string) $token, $live->signer);
        $expected = [
            $live->topics->for('', 'categories') => ['shop'],
            $live->topics->for('', 'orders:customer:42') => ['page'],
            $live->topics->for('', 'availability:7') => [TagsRead::WHOLE],
        ];
        ksort($expected);

        expect($decoded->topics)->toBe($expected)
            ->and($decoded->claims->route)->toBe('shop.orders')
            ->and($decoded->claims->build)->toBe('b1');
    });

    it('counts nothing a previous request\'s loaders read', function () {
        [$kernel, , $live] = kernel();

        $kernel->handle(visit('/shop/orders'), respond($minter, $token));
        expect($kernel->tagsRead->all())->toBe([]);

        // The reset contract cleared it; a minter built for a request with
        // none read would mint nothing.
        $kernel->tagsRead->read('left-behind');
        $kernel->scope->reset();
        expect($kernel->tagsRead->all())->toBe([])
            ->and($kernel->scope->names())->toContain('live');
    });

    // The time one request spent publishing to a hub of its own is not the
    // next one's: the reset contract renews its deadline (HubLink).
    it('gives the next request a whole deadline for publishing to a hub of its own', function () {
        $now = 1_000.0;
        $hub = \Tinvar\Live\Mercure::remote('http://hub.internal/.well-known/mercure', str_repeat('p', 32), str_repeat('s', 32), 'aud', post: static function () use (&$now): int {
            $now += 2.0;

            return 200;
        }, clock: static function () use (&$now): float {
            return $now;
        });
        [$kernel] = kernel(hub: $hub);

        $hub->publish('tinvar:a', '{}');
        $hub->publish('tinvar:b', '{}');
        expect(fn () => $hub->publish('tinvar:c', '{}'))->toThrow(\Tinvar\Live\HubUnavailable::class, 'has spent');

        $kernel->scope->reset();
        $hub->publish('tinvar:d', '{}');
        expect($kernel->scope->names())->toContain('hub');
    });

    it('is handed none without a session, or without live updates', function () {
        [$kernel] = kernel();
        $kernel->handle(visit('/shop/orders', session: ''), respond($minter, $token));
        expect($minter)->toBeNull();

        [$off] = kernel(live: false);
        $off->handle(visit('/shop/orders'), respond($minter, $token));
        expect($minter)->toBeNull()
            ->and($off->live_)->toBeNull();
    });
});

describe('the trade', function () {
    it('is mounted behind CSRF', function () {
        [$kernel, , $live] = kernel();
        $token = (string) $kernel->liveMinter(visit('/'), 'shop.orders', [])?->mint(["\0orders"]);

        $refused = $kernel->handle(
            new Request('POST', SubscribeEndpoint::PATH, ['content-type' => 'application/json'], cookies: [SESSION_COOKIE => 'session-a']),
            respond($m, $t),
            json_encode(['tokens' => [$token]]),
        );

        expect($refused->status)->toBe(419);
    });

    it('grants a token minted for the session asking, signed in as the same user', function () {
        [$kernel, $hub, $live] = kernel();
        $kernel->handle(visit('/shop/orders', headers: ['x-test-user' => 'u-1', 'x-test-epoch' => 'e1']), respond($minter, $token));

        $response = $kernel->handle(
            new Request(
                'POST',
                SubscribeEndpoint::PATH,
                ['content-type' => 'application/json', 'x-xsrf-token' => 'tok', 'x-test-user' => 'u-1', 'x-test-epoch' => 'e1'],
                cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 'tok'],
            ),
            respond($m, $t),
            json_encode(['tokens' => [$token]]),
        );

        expect($response->status)->toBe(200)
            ->and($hub->grants)->toHaveCount(1)
            ->and($hub->grants[0]['topics'])->toHaveCount(2);

        // Signed out since: the session it was minted for has ended.
        $ended = $kernel->handle(
            new Request(
                'POST',
                SubscribeEndpoint::PATH,
                ['content-type' => 'application/json', 'x-xsrf-token' => 'tok'],
                cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 'tok'],
            ),
            respond($m, $t),
            json_encode(['tokens' => [$token]]),
        );
        expect($ended->status)->toBe(401);
    });
});

it('publishes what an action invalidated, to every client, once it is committed', function () {
    [$kernel, $hub, $live] = kernel();

    $response = $kernel->handle(
        new Request('POST', '/_action/orders.place', ['x-xsrf-token' => 'tok'], cookies: [VerifyCsrfToken::COOKIE => 'tok']),
        respond($m, $t),
        '{}',
    );

    expect($response->status)->toBe(200)
        ->and($response->header('x-invalidated-tags'))->toBe('orders,orders:customer:42')
        ->and(array_column($hub->published, 'topic'))->toBe([
            $live->topics->for('', 'orders'),
            $live->topics->for('', 'orders:customer:42'),
        ]);
});
