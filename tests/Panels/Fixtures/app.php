<?php

declare(strict_types=1);

namespace Tinvar\Tests\Panels\Fixtures;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RouteToken;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Panels\Fixtures\Admin\Reports;

require_once __DIR__ . '/../../../../database/tests/Orm/Fixtures/tenant.php';

/**
 * A staff panel and a reseller panel in front of a few pages, an action and
 * a query: what the shop, which has no panels to speak of, does not
 * exercise.
 */
const SESSION = 'session-abc';

const KEY = 'panels-test-key';

/** What ran, in order, for assertions. */
final class Trace
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }

    public static function note(string $event): void
    {
        self::$events[] = $event;
    }
}

enum Kind: string
{
    case Reseller = 'reseller';
    case Customer = 'customer';
}

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Global: signs in whoever `x-user` names, as a session would; nobody without it. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-user');
        $this->auth->login($user === null ? null : new Member($user), $user === null ? '' : '1');

        return $next($request);
    }
}

/** Global, when a test wants it: an application choosing the panel itself, from `x-panel`. */
final readonly class HostPanel implements Middleware
{
    public function __construct(private PanelContext $panels) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $panel = $request->header('x-panel');

        return $panel === null ? $next($request) : $this->panels->within($panel, static fn (): Response => $next($request));
    }
}

/**
 * Who belongs where. `staff` is in the admin panel with every permission,
 * `clerk` without the ones that matter; `partner` is in the reseller panel,
 * as a reseller at acme and as a customer at globex. Every question asked is
 * recorded.
 */
final class Directory implements Memberships
{
    /** @var list<array{string, string, string}> user, panel, tenant */
    public array $asked = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        $this->asked[] = [$user->authIdentifier(), $panel, $tenant];
        Trace::note('membership');

        return match ([$user->authIdentifier(), $panel, $tenant]) {
            ['staff', 'admin', ''] => new Membership(permissions: ['admin.access', 'orders.view', 'orders.refund']),
            ['clerk', 'admin', ''] => new Membership(permissions: ['admin.access']),
            ['partner', 'reseller', 'acme'] => new Membership(Kind::Reseller),
            ['partner', 'reseller', 'globex'] => new Membership(Kind::Customer),
            default => null,
        };
    }
}

final class Hosts implements TenantHosts
{
    public function tenantForHost(string $host): ?string
    {
        return ['acme.test' => 'acme', 'globex.test' => 'globex'][$host] ?? null;
    }
}

/** The admin panel's guard: first in its stack. */
final class StaffGuard implements Middleware
{
    public function __construct(private readonly PanelContext $panels) {}

    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('guard:' . ($this->panels->currentOrNull() ?? 'none'));

        return $next($request);
    }
}

/** An extension point of the admin panel's stack: after the membership check. */
final class RequireMfa implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('mfa');

        return $next($request);
    }
}

/** A page's own middleware: after its panel's whole stack. */
final class PageMiddleware implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::note('page-middleware');

        return $next($request);
    }
}

final readonly class PanelPageData extends Data
{
    /** @param list<string> $permissions */
    public function __construct(public string $panel, public array $permissions) {}
}

/** Reports the panel it loaded in and the permissions the check found. */
abstract readonly class ReportsPanel
{
    public function __construct(private PanelContext $panels) {}

    public function __invoke(Request $request): PanelPageData
    {
        Trace::note('loader');

        return new PanelPageData(
            $this->panels->currentOrNull() ?? '',
            RequirePanelMembership::of($request)->permissions ?? [],
        );
    }
}

/** The route group: everything under /admin is in the admin panel. */
#[Layout('/admin', view: 'admin/layout'), Panel('admin')]
final class AdminGroup {}

#[Page('/admin/dashboard', view: 'admin/dashboard', name: 'admin.dashboard'), UseMiddleware(PageMiddleware::class)]
final readonly class AdminDashboard extends ReportsPanel {}

/** Narrows further than its group: a permission of its own. */
#[Page('/admin/orders', view: 'admin/orders', name: 'admin.orders'), Panel('admin', permission: 'orders.view')]
final readonly class AdminOrders extends ReportsPanel {}

#[Page('/home', view: 'home', name: 'home')]
final readonly class Home extends ReportsPanel {}

#[Page('/reseller', view: 'reseller', name: 'reseller'), TenantFrom(Host::class), Panel('reseller', requires: Kind::Reseller)]
final readonly class ResellerHome extends ReportsPanel {}

#[Panel('admin')]
final class AdminActions
{
    #[Action, NoAuthorization(reason: 'a fixture action'), Panel('admin', permission: 'orders.refund')]
    public function refund(): ActionResult
    {
        Trace::note('action');

        return ActionResult::ok('refunded')->withRoutes('admin.orders');
    }
}

#[Query(name: 'admin.stats'), Panel('admin'), NoAuthorization(reason: 'a fixture query')]
final class AdminStats
{
    public function __invoke(): int
    {
        Trace::note('query');

        return 42;
    }
}

/** Narrower than its panel: a permission of its own. Registered only when a test asks. */
#[Query(name: 'admin.refunds'), Panel('admin', permission: 'orders.refund'), NoAuthorization(reason: 'a fixture query')]
final class AdminRefunds
{
    public function __invoke(): string
    {
        Trace::note('query:refunds');

        return 'refunds';
    }
}

/**
 * The application around them.
 */
final class App
{
    public readonly Kernel $kernel;
    public readonly Signer $fragmentSigner;
    public readonly Signer $routeSigner;
    public readonly FragmentSupport $support;
    public readonly AuthContext $auth;
    public readonly PanelContext $panels;
    public readonly Directory $directory;
    public readonly QueryRegistry $queries;
    public readonly Orm $orm;

    /** @var list<string> the panel each fragment rendered in */
    public array $fragments = [];

    /**
     * @param list<class-string> $routes beside the fixture's own
     * @param list<class-string<Middleware>> $global
     * @param list<class-string> $extraQueries beside AdminStats
     * @param (\Closure(string): list<string>)|null $allowedQueries each route's query allowlist, by name
     */
    public function __construct(
        array $routes = [],
        array $global = [SignIn::class],
        ?FragmentManifest $manifest = null,
        array $extraQueries = [],
        ?\Closure $allowedQueries = null,
    ) {
        Trace::reset();
        $fixture = new TenantFixture();
        $orm = $this->orm = new Orm($fixture->connection, new IdentityMap('panels-test'));
        $this->auth = new AuthContext();
        $this->panels = new PanelContext();
        $this->directory = new Directory();
        $this->fragmentSigner = new Signer(KEY, FragmentToken::PURPOSE);
        $this->routeSigner = new Signer(KEY, RouteToken::PURPOSE);

        $container = (new Container())
            ->instance(PanelContext::class, $this->panels)
            ->instance(TenantHosts::class, new Hosts());

        $registry = new RouteRegistry();
        $registry->registerAll([AdminGroup::class, AdminDashboard::class, AdminOrders::class, Home::class, ResellerHome::class, Reports::class, ...$routes]);

        $actions = new ActionRegistry($container->resolver());
        $actions->register(AdminActions::class);

        $queries = $this->queries = new QueryRegistry($container->resolver());
        $queries->registerAll([AdminStats::class, ...$extraQueries]);

        $this->support = new FragmentSupport(
            signer: $this->fragmentSigner,
            builds: new BuildRegistry('build-1'),
            render: function (): Response {
                Trace::note('fragment');
                $this->fragments[] = $this->panels->currentOrNull() ?? '';

                return Response::make(200, 'RSC-FRAGMENT');
            },
            sessionId: static fn (Request $r): string => SESSION,
            panels: $this->panels,
            allowedQueries: $allowedQueries,
            orm: $orm,
            routeSigner: $this->routeSigner,
            renderRoute: static function (): string {
                Trace::note('rerender');

                return 'RSC-ROUTE';
            },
            auth: $this->auth,
        );

        $this->kernel = new Kernel(
            orm: $orm,
            routes: $registry,
            queries: $queries,
            actions: $actions,
            auth: $this->auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => ['admin.stats'],
            globalMiddleware: $global,
            gate: new AllowAllGate(),
            fragments: $this->support,
            panels: $this->panels,
            manifest: $manifest,
            container: $container,
            historyKeys: new HistoryKeys(new Signer(KEY, HistoryKeys::PURPOSE), static fn (Request $r): string => SESSION),
            panelRegistry: self::panels(),
            memberships: $this->directory,
        );
    }

    public static function panels(): PanelRegistry
    {
        return new PanelRegistry(
            new PanelDefinition(
                'admin',
                guard: StaffGuard::class,
                middleware: [RequireMfa::class],
                namespaces: ['Tinvar\\Tests\\Panels\\Fixtures\\Admin\\'],
                permission: 'admin.access',
            ),
            new PanelDefinition('reseller'),
        );
    }

    /** A page as JSON -- its data and history key -- or the refusal. */
    public function get(string $path, ?string $user, array $headers = [], string $host = 'shop.test'): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host, ...($user === null ? [] : ['x-user' => $user]), ...$headers]),
            static function (LoadedRoute $loaded, Request $passed): Response {
                return Response::make(200, json_encode([
                    'page' => $loaded->page === null ? [] : get_object_vars($loaded->page),
                    'historyKey' => $passed->attribute(Kernel::HISTORY_KEY),
                ], JSON_THROW_ON_ERROR));
            },
        );
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, ?string $user, array $body = []): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                $path,
                [
                    'host' => 'shop.test',
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => 'csrf',
                    ...($user === null ? [] : ['x-user' => $user]),
                ],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf'],
            ),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** A fragment token for a route, minted as $user in $panel. */
    public function fragmentToken(string $route, string $user, string $panel): string
    {
        return $this->auth->actingAs(
            new Member($user),
            fn (): string => $this->support->minterFor(Request::get('/'), $route, [], panel: $panel)->mint('Recent', []),
            '1',
        );
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}

// Its class extends ReportsPanel, so it is read once that is declared.
require_once __DIR__ . '/Admin/reports.php';
