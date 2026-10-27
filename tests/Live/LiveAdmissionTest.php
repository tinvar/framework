<?php

declare(strict_types=1);

/**
 * A live token says which page a client holds, never whether it may go on
 * holding it. Every trade at /_live/subscribe -- the first and every renewal
 * -- runs the page's route as /_fragment does: its tenant resolved from the
 * request, its panel's guard and membership check, its #[Authorize] chain.
 *
 * Found in review: renewal checked only that the tokens agreed with each
 * other on tenant and panel, so a user taken out of a panel went on being
 * granted its invalidations, renewal after renewal, for the token's whole
 * lifetime.
 */

namespace Tinvar\Tests\Live\LiveAdmission;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
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
use Tinvar\Tenancy\TenantSource;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/tenancy.php';

const KEY = 'live-admission-test-application-key-012345';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Signs in whoever the test says, at the epoch it says, as a session middleware would. */
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

/** Who is staff: changed by the test between trades. */
final class Staff implements Memberships
{
    /** @var array<string, true> */
    public static array $members = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        return isset(self::$members[$user->authIdentifier()]) ? new Membership(permissions: ['orders.view-all']) : null;
    }
}

/** The tenant a request names in a header, as a host would. */
final class FromHeader implements TenantSource
{
    public function tenantFor(Request $request): ?string
    {
        return $request->header('x-tenant');
    }
}

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/admin/orders', view: 'admin/orders', name: 'admin.orders'), Panel('admin', permission: 'orders.view-all'),
    NoAuthorization(reason: 'the panel is the check')]
final class AdminOrders
{
    public function __construct(private readonly TagsRead $tags) {}

    public function __invoke(): TitleData
    {
        $this->tags->read('orders');

        return new TitleData('Every order');
    }
}

#[Page('/store/orders', view: 'store/orders', name: 'store.orders'), TenantFrom(FromHeader::class),
    NoAuthorization(reason: 'a test page')]
final class StoreOrders
{
    public function __construct(private readonly TagsRead $tags) {}

    public function __invoke(): TitleData
    {
        $this->tags->read('orders');

        return new TitleData('A store\'s orders');
    }
}

/** @return array{Kernel, MemoryHub, LiveUpdates} */
function kernel(): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'), tenants: Tenancy::flat());
    $auth = new AuthContext();
    SignIn::$auth = $auth;
    Staff::$members = ['staff-1' => true];
    $container = new Container();

    $routes = new RouteRegistry();
    $routes->registerAll([AdminOrders::class, StoreOrders::class]);
    $hub = new MemoryHub();
    $live = LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');

    return [new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        tenants: new \Tinvar\Tenancy\Tenancy($container->resolver()),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignIn::class],
        gate: new AllowAllGate(),
        builds: new BuildRegistry('b1'),
        container: $container,
        panelRegistry: new PanelRegistry(new PanelDefinition('admin')),
        memberships: new Staff(),
        live: $live,
    ), $hub, $live];
}

/** @param array<string, string> $headers */
function page(Kernel $kernel, string $path, array $headers): ?string
{
    $token = null;
    $response = $kernel->handle(
        new Request('GET', $path, $headers, cookies: [SESSION_COOKIE => 'session-a']),
        static function (LoadedRoute $loaded, Request $request) use (&$token): Response {
            $minter = $request->attribute(Kernel::LIVE_MINTER);
            $token = $minter instanceof LiveMinter ? $minter->mint([]) : null;

            return Response::make(200, '<html></html>');
        },
    );
    expect($response->status)->toBe(200);

    return $token;
}

/**
 * @param list<string> $tokens
 * @param array<string, string> $headers
 * @return array{Response, array<string, mixed>}
 */
function trade(Kernel $kernel, array $tokens, array $headers): array
{
    $response = $kernel->handle(
        new Request(
            'POST',
            SubscribeEndpoint::PATH,
            ['content-type' => 'application/json', 'x-xsrf-token' => 'tok', ...$headers],
            cookies: [SESSION_COOKIE => 'session-a', VerifyCsrfToken::COOKIE => 'tok'],
        ),
        static fn (): Response => Response::make(500),
        json_encode(['tokens' => $tokens], JSON_THROW_ON_ERROR),
    );

    /** @var array<string, mixed> $body */
    $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return [$response, $body];
}

const STAFF = ['x-test-user' => 'staff-1', 'x-test-epoch' => 'e1'];

describe('a user taken out of a panel', function () {
    it('cannot renew a subscription to it, and is granted none of its topics after', function () {
        [$kernel, $hub, $live] = kernel();
        $token = (string) page($kernel, '/admin/orders', STAFF);

        [$first, $body] = trade($kernel, [$token], STAFF);
        expect($first->status)->toBe(200)
            ->and($body['topics'])->toBe([$live->topics->for('', 'orders')]);
        $renewed = $body['tokens'][0];

        // No longer staff -- and the application has not moved the epoch,
        // so only the panel's own membership check can say so.
        Staff::$members = [];
        $grants = \count($hub->grants);

        [$second, $body] = trade($kernel, [$renewed], STAFF);

        expect($second->status)->toBe(200)
            ->and($body['tokens'])->toBe([null])
            ->and($body['topics'])->toBe([])
            // Whatever the hub was letting it hear is taken away, and nothing
            // new is granted: no further admin events.
            ->and(\count($hub->grants))->toBe($grants)
            ->and($hub->revoked)->toBe(1);

        // Nor can it go back to the first token.
        [, $again] = trade($kernel, [$token], STAFF);
        expect($again['tokens'])->toBe([null]);
    });

    it('is refused with the 401 that purges the client once the application moves the epoch, as it should', function () {
        [$kernel, $hub] = kernel();
        $token = (string) page($kernel, '/admin/orders', STAFF);
        [, $body] = trade($kernel, [$token], STAFF);

        Staff::$members = [];
        [$refused, $error] = trade($kernel, [$body['tokens'][0]], ['x-test-user' => 'staff-1', 'x-test-epoch' => 'e2']);

        expect($refused->status)->toBe(401)
            ->and($error)->toBe(['error' => ['code' => 'session_changed']]);
    });

    it('is still granted while a member, renewal after renewal', function () {
        [$kernel, $hub] = kernel();
        $token = (string) page($kernel, '/admin/orders', STAFF);

        foreach ([1, 2, 3] as $round) {
            [$response, $body] = trade($kernel, [$token], STAFF);
            expect($response->status)->toBe(200)
                ->and($body['tokens'][0])->toBeString();
            $token = $body['tokens'][0];
        }

        expect($hub->grants)->toHaveCount(3);
    });
});

describe('a tenant', function () {
    it('refuses a token for tenant A on a request resolved to tenant B', function () {
        [$kernel, $hub, $live] = kernel();
        $token = (string) page($kernel, '/store/orders', ['x-tenant' => 'a', ...STAFF]);

        [$elsewhere, $body] = trade($kernel, [$token], ['x-tenant' => 'b', ...STAFF]);
        expect($elsewhere->status)->toBe(200)
            ->and($body['tokens'])->toBe([null])
            ->and($body['topics'])->toBe([])
            ->and($hub->grants)->toBe([]);

        [, $body] = trade($kernel, [$token], ['x-tenant' => 'a', ...STAFF]);
        expect($body['topics'])->toBe([$live->topics->for('a', 'orders')]);
    });

    it('refuses one on a request the route cannot place in any tenant', function () {
        [$kernel, $hub] = kernel();
        $token = (string) page($kernel, '/store/orders', ['x-tenant' => 'a', ...STAFF]);

        [, $body] = trade($kernel, [$token], STAFF);

        expect($body['tokens'])->toBe([null])
            ->and($hub->grants)->toBe([]);
    });

    it('refuses a token claiming a tenant its page was never rendered in, even with a good signature', function () {
        [$kernel, $hub, $live] = kernel();
        $forged = (string) new LiveMinter(
            signer: $live->signer,
            topics: $live->topics,
            route: 'store.orders',
            params: [],
            panel: '',
            tenant: 'a',
            build: 'b1',
            sessionId: 'session-a',
            user: 'staff-1',
            authEpoch: 'e1',
        )->mint(["b\0orders"]);

        [, $body] = trade($kernel, [$forged], ['x-tenant' => 'b', ...STAFF]);

        expect($body['tokens'])->toBe([null])
            ->and($hub->grants)->toBe([]);
    });
});
