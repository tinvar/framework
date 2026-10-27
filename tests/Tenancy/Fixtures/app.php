<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FragmentSupport;
use Tinvar\Http\HistoryKeys;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Connection;
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
use Tinvar\Tenancy\Signature;
use Tinvar\Tenancy\SignedUrls;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/../../../../database/tests/Orm/Fixtures/tenant.php';

/**
 * A small multi-tenant application: two tenants' scripts behind host
 * binding, signed links, an action and a query -- what the shop, which has
 * one tenant, does not exercise.
 */
const SESSION = 'session-abc';

const KEY = 'tenancy-test-key';

/** acme.test, globex.test, and reseller.test -- above both, given a tree -- and nothing else. */
final class Hosts implements TenantHosts
{
    public function tenantForHost(string $host): ?string
    {
        return ['acme.test' => 'acme', 'globex.test' => 'globex', 'reseller.test' => 'reseller'][$host] ?? null;
    }
}

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Global: signs the same member in on every request, as a session would. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $this->auth->login(new Member('m-1'), '1');

        return $next($request);
    }
}

/**
 * Global: what an application that keeps a session's tenant does -- runs the
 * request as the tenant an `x-session-tenant` header names, standing in for
 * one read from the session.
 */
final readonly class SessionTenant implements Middleware
{
    public function __construct(private Orm $orm) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = $request->header('x-session-tenant');

        return $tenant === null ? $next($request) : $this->orm->actAs($tenant, static fn (): Response => $next($request));
    }
}

final readonly class ScriptsData extends Data
{
    /** @param list<string> $names */
    public function __construct(public string $tenant, public array $names) {}
}

final readonly class ScriptData extends Data
{
    public function __construct(public string $name) {}
}

/** The route group: every page under /t runs as its host's tenant. */
#[Layout('/t', view: 't/layout'), TenantFrom(Host::class)]
final class TenantGroup {}

#[Page('/t/scripts', view: 't/scripts', name: 'scripts.index')]
final readonly class ScriptsPage
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptsData
    {
        return new ScriptsData($this->orm->tenant(), array_map(
            static fn (Script $script): string => $script->name,
            $this->orm->all(Script::class, 'name'),
        ));
    }
}

#[Page('/t/scripts/{script}', view: 't/script', name: 'scripts.show'), Authorize('view', 'script')]
final class ScriptPage
{
    public function __invoke(Script $script): ScriptData
    {
        return new ScriptData($script->name);
    }
}

/** Declares its own tenant source, under no group. */
#[Page('/own/scripts', view: 'own/scripts', name: 'own.scripts'), TenantFrom(Host::class)]
final readonly class OwnScriptsPage
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptsData
    {
        return new ScriptsData($this->orm->tenant(), array_map(
            static fn (Script $script): string => $script->name,
            $this->orm->all(Script::class, 'name'),
        ));
    }
}

/** Reads a tenant's scripts and declares nowhere to take the tenant from. */
#[Page('/forgot/scripts', view: 'forgot/scripts', name: 'forgot.scripts')]
final readonly class ForgotTenantPage
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptsData
    {
        return new ScriptsData('', array_map(
            static fn (Script $script): string => $script->name,
            $this->orm->all(Script::class, 'name'),
        ));
    }
}

/**
 * Reads around the ORM, with no tenant predicate: every tenant's rows,
 * unless the database's row-level security stands behind it.
 */
#[Page('/t/raw', view: 't/raw', name: 'scripts.raw')]
final readonly class RawScriptsPage
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptsData
    {
        return new ScriptsData($this->orm->tenant(), array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->orm->connection()->select('SELECT name FROM scripts ORDER BY name'),
        ));
    }
}

/** An emailed link: the tenant is the one its signature names. */
#[Page('/links/scripts', view: 'links/scripts', name: 'links.scripts'), TenantFrom(Signature::class)]
final readonly class SignedScriptsPage
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptsData
    {
        return new ScriptsData($this->orm->tenant(), array_map(
            static fn (Script $script): string => $script->name,
            $this->orm->all(Script::class, 'name'),
        ));
    }
}

final class ScriptActions
{
    #[Action, TenantFrom(Host::class), Authorize('update', 'script')]
    public function touch(Script $script): ActionResult
    {
        return ActionResult::ok($script->name)->withRoutes('scripts.index');
    }

    /** No tenant source: model arguments meet NoTenantContext. */
    #[Action, Authorize('update', 'script')]
    public function forgot(Script $script): ActionResult
    {
        return ActionResult::ok($script->name);
    }
}

#[Query(name: 'scripts.names'), TenantFrom(Host::class), NoAuthorization(reason: 'a fixture listing its tenant\'s scripts')]
final readonly class ScriptNames
{
    public function __construct(private Orm $orm) {}

    /** @return list<string> */
    public function __invoke(): array
    {
        return array_map(static fn (Script $script): string => $script->name, $this->orm->all(Script::class, 'name'));
    }
}

/**
 * The application around them.
 */
final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly Signer $fragmentSigner;
    public readonly Signer $routeSigner;
    public readonly FragmentSupport $support;
    public readonly SignedUrls $urls;
    public readonly AuthContext $auth;

    /** @var list<array{route: string, tenant: string}> each route re-rendered after an action */
    public array $rerendered = [];

    /** @var list<string> the tenant each fragment rendered as */
    public array $fragments = [];

    /** @param list<class-string<Middleware>> $global */
    public function __construct(
        Connection $connection,
        array $global = [SignIn::class],
        ?FragmentManifest $manifest = null,
        ?TenantHierarchy $tenants = null,
    ) {
        $this->orm = new Orm($connection, new IdentityMap('tenant-test'), $tenants ?? Tenancy::flat());
        $this->auth = new AuthContext();
        $this->fragmentSigner = new Signer(KEY, FragmentToken::PURPOSE);
        $this->routeSigner = new Signer(KEY, RouteToken::PURPOSE);
        $this->urls = new SignedUrls(new Signer(KEY, SignedUrls::PURPOSE));
        $panels = new PanelContext();

        $container = (new Container())
            ->instance(TenantHosts::class, new Hosts())
            ->instance(SignedUrls::class, $this->urls);

        $routes = new RouteRegistry();
        $routes->registerAll([
            TenantGroup::class,
            ScriptsPage::class,
            ScriptPage::class,
            RawScriptsPage::class,
            OwnScriptsPage::class,
            ForgotTenantPage::class,
            SignedScriptsPage::class,
        ]);

        $actions = new ActionRegistry($container->resolver());
        $actions->register(ScriptActions::class);

        $queries = new QueryRegistry($container->resolver());
        $queries->register(ScriptNames::class);

        $this->support = new FragmentSupport(
            signer: $this->fragmentSigner,
            builds: new BuildRegistry('build-1'),
            render: function (): Response {
                $this->fragments[] = $this->orm->tenant();

                return Response::make(200, 'RSC-FRAGMENT');
            },
            sessionId: static fn (Request $r): string => SESSION,
            panels: $panels,
            routeSigner: $this->routeSigner,
            renderRoute: function (LoadedRoute $loaded): string {
                $this->rerendered[] = ['route' => $loaded->chain->page->name ?? '', 'tenant' => $this->orm->tenant()];

                return 'RSC-ROUTE';
            },
            orm: $this->orm,
            auth: $this->auth,
        );

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: $queries,
            actions: $actions,
            auth: $this->auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            tenants: new \Tinvar\Tenancy\Tenancy($container->resolver()),
            allowedQueries: static fn (Request $r): array => ['scripts.names'],
            globalMiddleware: $global,
            gate: new AllowAllGate(),
            fragments: $this->support,
            panels: $panels,
            manifest: $manifest,
            container: $container,
            historyKeys: new HistoryKeys(new Signer(KEY, HistoryKeys::PURPOSE), static fn (Request $r): string => SESSION),
        );
    }

    /**
     * A fragment token for the scripts page, minted as SignIn's member, for
     * $tenant.
     */
    public function fragmentToken(string $tenant): string
    {
        return $this->auth->actingAs(
            new Member('m-1'),
            fn (): string => $this->support->minterFor(Request::get('/'), 'scripts.index', [], tenant: $tenant)->mint('Recent', []),
            '1',
        );
    }

    /**
     * A page: its loader's data and the history key it was handed, as JSON,
     * or the refusal.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function get(string $path, string $host, array $headers = [], array $query = []): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host, ...$headers], $query),
            static function (LoadedRoute $loaded, Request $passed): Response {
                return Response::make(200, json_encode([
                    'page' => $loaded->page === null ? [] : get_object_vars($loaded->page),
                    'historyKey' => $passed->attribute(Kernel::HISTORY_KEY),
                ], JSON_THROW_ON_ERROR));
            },
        );
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, string $host, array $body = []): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                $path,
                ['host' => $host, 'content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => 'csrf'],
                [],
                [],
                ['__Host-XSRF-TOKEN' => 'csrf'],
            ),
            static fn (): Response => Response::make(500, 'not a page'),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
