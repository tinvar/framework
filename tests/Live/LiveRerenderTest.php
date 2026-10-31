<?php

declare(strict_types=1);

/**
 * A page an action re-renders in place answers with the live token for
 * what it read then (ADR 0048): minted as a page render's is, from its
 * loaders' tags by segment and its queries', bound to the request as the
 * action left it -- so a page drawn again under a moved auth epoch, or
 * reading other tags, stops listening as it was first drawn.
 */

namespace Tinvar\Tests\Live\LiveRerender;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
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
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveMinter;
use Tinvar\Live\LiveToken;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\TagsRead;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const KEY = 'live-rerender-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Signs in u-1 at the epoch the test keeps, as an application's session middleware would. */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;

    public static string $epoch = 'e1';

    public function handle(Request $request, \Closure $next): Response
    {
        self::$auth?->login(new Member('u-1'), self::$epoch);

        return $next($request);
    }
}

final readonly class TitleData extends Data
{
    public function __construct(public string $title) {}
}

#[Page('/orders', view: 'orders', name: 'orders'), NoAuthorization(reason: 'a test page')]
final class OrdersPage
{
    public static string $tag = 'orders:customer:42';

    public function __construct(private readonly TagsRead $tags) {}

    public function __invoke(): TitleData
    {
        $this->tags->read(self::$tag);

        return new TitleData('Orders');
    }
}

final class OrderActions
{
    public function __construct(private readonly TagsRead $tags) {}

    #[Action(name: 'orders.cancel'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function cancel(): ActionResult
    {
        // Read by the action, not by the page: nothing the page listens for.
        $this->tags->read('audit');

        return ActionResult::revalidate('orders');
    }

    #[Action(name: 'orders.promote'), PublicAccess, NoAuthorization(reason: 'a test action')]
    public function promote(): ActionResult
    {
        // A role change: the application moves the user's auth epoch.
        SignIn::$epoch = 'e2';
        SignIn::$auth?->login(new Member('u-1'), 'e2');

        return ActionResult::revalidate('orders');
    }
}

/**
 * @param \Closure(LoadedRoute, Request): (string|Response) $renderRoute
 * @return array{Kernel, LiveUpdates, Signer}
 */
function kernel(\Closure $renderRoute, bool $live = true): array
{
    $orm = new Orm(PdoConnection::sqlite(':memory:', 'test'), new IdentityMap('test'), tenants: Tenancy::flat());
    $auth = new AuthContext();
    SignIn::$auth = $auth;
    SignIn::$epoch = 'e1';
    $container = new Container();
    $container->instance(Orm::class, $orm);

    $routes = new RouteRegistry();
    $routes->registerAll([OrdersPage::class]);
    $actions = new ActionRegistry($container->resolver());
    $actions->register(OrderActions::class);

    $sessionId = static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '';
    $routeSigner = new Signer(KEY, RouteToken::PURPOSE);
    $updates = LiveUpdates::fromKey(KEY, new MemoryHub(), $sessionId);

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
        fragments: new FragmentSupport(
            signer: new Signer(KEY, FragmentToken::PURPOSE),
            builds: new BuildRegistry('b1'),
            render: static fn (): Response => Response::make(200),
            sessionId: $sessionId,
            renderRoute: $renderRoute,
            routeSigner: $routeSigner,
        ),
        live: $live ? $updates : null,
    ), $updates, $routeSigner];
}

/**
 * Answers as a page render does: the payload, and the token minted with
 * what the render's queries read.
 *
 * @param list<string> $rendered
 */
function answering(array $rendered = ["\0availability:7"]): \Closure
{
    return static function (LoadedRoute $loaded, Request $request) use ($rendered): Response {
        $minter = $request->attribute(Kernel::LIVE_MINTER);
        $token = $minter instanceof LiveMinter ? $minter->mint($rendered) : null;
        $response = Response::make(200, 'payload:' . $loaded->chain->page->name, ['content-type' => 'text/x-component']);

        return $token === null ? $response : $response->withHeader('x-live-token', $token);
    };
}

/** The action, posted from the orders page by the session holding its route token. */
function post(Kernel $kernel, Signer $routeSigner, string $action): Response
{
    $token = new RouteToken(new TokenClaims(
        route: 'orders',
        params: [],
        panel: '',
        tenant: '',
        build: 'b1',
        sessionHash: TokenClaims::hashSession($routeSigner, 'session-a'),
        expiresAt: time() + 600,
        issuedAt: time(),
        // Bound to who sends the action, before it runs: u-1 at the epoch
        // the session holds, whatever the action moves it to.
        authHash: TokenClaims::hashAuth($routeSigner, 'u-1', SignIn::$epoch),
    ))->encode($routeSigner);

    return $kernel->handle(
        new Request('POST', "/_action/{$action}", ['x-xsrf-token' => 'tok', 'content-type' => 'application/json'], cookies: [
            SESSION_COOKIE => 'session-a',
            VerifyCsrfToken::COOKIE => 'tok',
        ]),
        static fn (): Response => Response::make(200),
        json_encode(['_routes' => [$token]], JSON_THROW_ON_ERROR),
    );
}

it('answers each re-render with the live token for what it read, and nothing the action read', function () {
    [$kernel, $live, $routes] = kernel(answering());
    OrdersPage::$tag = 'orders:customer:7';

    $body = json_decode(post($kernel, $routes, 'orders.cancel')->body(), true);

    expect($body['payloads'])->toBe(['orders?' => 'payload:orders'])
        ->and(array_keys($body['live']))->toBe(['orders?']);

    $token = LiveToken::decode((string) $body['live']['orders?'], $live->signer);
    $expected = [
        $live->topics->for('', 'orders:customer:7') => ['page'],
        $live->topics->for('', 'availability:7') => [TagsRead::WHOLE],
    ];
    ksort($expected);
    expect($token->topics)->toBe($expected)
        ->and($token->claims->route)->toBe('orders')
        ->and($token->claims->belongsTo($live->signer, 'session-a'))->toBeTrue()
        ->and($token->claims->authHash)->toBe(TokenClaims::hashAuth($live->signer, 'u-1', 'e1'));
})->after(function () {
    OrdersPage::$tag = 'orders:customer:42';
});

it('binds the token to the epoch the action moved the user to', function () {
    [$kernel, $live, $routes] = kernel(answering());

    $body = json_decode(post($kernel, $routes, 'orders.promote')->body(), true);
    $token = LiveToken::decode((string) $body['live']['orders?'], $live->signer);

    expect($token->claims->authHash)->toBe(TokenClaims::hashAuth($live->signer, 'u-1', 'e2'))
        ->and($token->claims->authHash)->not->toBe(TokenClaims::hashAuth($live->signer, 'u-1', 'e1'));
});

it('answers null for a re-render that answered as a page and minted nothing', function () {
    [$kernel, , $routes] = kernel(static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'payload'));

    $body = json_decode(post($kernel, $routes, 'orders.cancel')->body(), true);

    // As a payload with no X-Live-Token: null in its place, so the client
    // drops the token it held rather than keep listening as before.
    expect($body['live'])->toBe(['orders?' => null]);
});

it('says nothing of live tokens for a render that answers with its payload alone, or without live updates', function () {
    // One kernel at a time: SignIn signs in on the AuthContext of the last
    // one built, and the route token is held to who sends the action.
    [$withString, , $routes] = kernel(static fn (LoadedRoute $l, Request $r): string => 'payload');
    $string = json_decode(post($withString, $routes, 'orders.cancel')->body(), true);

    [$withoutLive, , $routesToo] = kernel(answering(), live: false);
    $off = json_decode(post($withoutLive, $routesToo, 'orders.cancel')->body(), true);

    expect($string['payloads'])->toBe(['orders?' => 'payload'])
        ->and($string)->not->toHaveKey('live')
        ->and($off['payloads'])->toBe(['orders?' => 'payload:orders'])
        ->and($off)->not->toHaveKey('live');
});

it('sends no payload for a re-render that answered with an error', function () {
    [$kernel, , $routes] = kernel(static fn (LoadedRoute $l, Request $r): Response => Response::make(500, 'boom'));

    $body = json_decode(post($kernel, $routes, 'orders.cancel')->body(), true);

    expect($body['payloads'])->toBe([])
        ->and($body)->not->toHaveKey('live');
});
