<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures\Hierarchy;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
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
use Tinvar\Auth\TenantMemberships;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Tenancy;
use Tinvar\Tenancy\TenantHosts;
use Tinvar\Tenancy\TenantSessions;
use Tinvar\Tests\Orm\Fixtures\Hierarchy\Tenants;
use Tinvar\Tests\Tenancy\Fixtures\Tree;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/../../../../database/tests/Orm/Fixtures/hierarchy.php';
require_once __DIR__ . '/tree.php';

/**
 * The hierarchy behind a request path: a reseller panel whose memberships
 * reach the reseller's subtree, a customer panel whose do not, and an admin
 * panel only the platform's own hosts carry -- on the platform's hosts, the
 * tenants' subdomains of them, and the reseller's custom domain.
 */
final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** What ran, in order. */
final class Trace
{
    /** @var list<string> */
    public static array $events = [];
}

/** Global: signs in whoever `x-user` names, as a session would. */
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

/** Global: runs the request as the tenant `x-session-tenant` names, as a session would. */
final readonly class SessionTenant implements Middleware
{
    public function __construct(private Orm $orm) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = $request->header('x-session-tenant');

        return $tenant === null ? $next($request) : $this->orm->actAs($tenant, static fn (): Response => $next($request));
    }
}

/**
 * Who belongs where. `partner` is the reseller's, with the permission its
 * panel needs, and a customer of acme, acme-west, other and the reseller
 * itself; `clerk` is the reseller's without it; `staff` is in the admin
 * panel. Each tenant a membership was asked in is recorded, with the
 * tenant the ORM ran as when it was.
 */
final class Directory implements Memberships, TenantMemberships
{
    /** @var list<array{string, string}> tenant asked about, tenant running as */
    public array $asked = [];

    public function __construct(private readonly Orm $orm) {}

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        $this->asked[] = [$tenant, $this->orm->tenant()];

        return match (true) {
            [$user->authIdentifier(), $panel, $tenant] === ['partner', 'reseller', 'reseller'] => new Membership('reseller', ['reseller.access']),
            [$user->authIdentifier(), $panel, $tenant] === ['clerk', 'reseller', 'reseller'] => new Membership('reseller'),
            [$user->authIdentifier(), $panel, $tenant] === ['staff', 'admin', ''] => new Membership(),
            $user->authIdentifier() === 'partner' && $panel === 'customer'
                && \in_array($tenant, ['acme', 'acme-west', 'other', 'reseller'], true) => new Membership('customer'),
            default => null,
        };
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        return match ([$user->authIdentifier(), $panel]) {
            ['partner', 'customer'] => ['other', 'acme', 'reseller', 'acme-west'],
            ['partner', 'reseller'] => ['reseller'],
            default => [],
        };
    }
}

final class Hosts implements TenantHosts
{
    public function tenantForHost(string $host): ?string
    {
        return [
            'reseller.platform.test' => 'reseller',
            'acme.platform.test' => 'acme',
            'other.platform.test' => 'other',
            'reseller.example' => 'reseller',
        ][$host] ?? null;
    }
}

/** A switch's last step, as an application keeping sessions would take it. */
final class Sessions implements TenantSessions
{
    /** @var list<array{string, string}> user, tenant */
    public array $switched = [];

    public ?string $answer = null;

    public function switchTo(Request $request, Authenticatable $user, string $tenant): string
    {
        $this->switched[] = [$user->authIdentifier(), $tenant];

        return $this->answer ?? 'session-' . \count($this->switched);
    }
}

/** The admin panel's guard: records that it ran, as a sign-in redirect would. */
final class AdminGuard implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trace::$events[] = 'admin-guard';

        return $next($request);
    }
}

final readonly class ScriptsData extends Data
{
    /** @param list<string> $names */
    public function __construct(public string $tenant, public array $names) {}
}

/** Reads the scripts it reaches, as the tenant the request runs as. */
abstract readonly class ReadsScripts
{
    public function __construct(protected Orm $orm) {}

    /** @return list<string> */
    protected function names(): array
    {
        return array_map(static fn (Script $script): string => $script->name, $this->orm->all(Script::class, 'name'));
    }
}

/** The reseller's view of its customers: its panel's membership reaches the subtree. */
#[Page('/r/scripts', view: 'r/scripts', name: 'reseller.scripts'), TenantFrom(Host::class), Panel('reseller')]
final readonly class ResellerScripts extends ReadsScripts
{
    public function __invoke(): ScriptsData
    {
        return $this->orm->withinSubtree(fn (): ScriptsData => new ScriptsData($this->orm->tenant(), $this->names()));
    }
}

#[Page('/c/scripts', view: 'c/scripts', name: 'customer.scripts'), TenantFrom(Host::class), Panel('customer')]
final readonly class CustomerScripts extends ReadsScripts
{
    public function __invoke(): ScriptsData
    {
        return new ScriptsData($this->orm->tenant(), $this->names());
    }
}

/** A customer page that widens, which its panel's membership does not grant. */
#[Page('/c/widen', view: 'c/widen', name: 'customer.widen'), TenantFrom(Host::class), Panel('customer')]
final readonly class CustomerWidens extends ReadsScripts
{
    public function __invoke(): ScriptsData
    {
        return $this->orm->withinSubtree(fn (): ScriptsData => new ScriptsData($this->orm->tenant(), $this->names()));
    }
}

#[Page('/admin/home', view: 'admin/home', name: 'admin.home'), Panel('admin')]
final readonly class AdminHome extends ReadsScripts
{
    public function __invoke(): ScriptsData
    {
        return new ScriptsData('', []);
    }
}

final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public readonly Directory $directory;
    public readonly PanelContext $panels;

    /** @var list<string> each failure the Kernel logged */
    public array $log = [];

    public function __construct(?Tree $tree = new Tree(), ?Realms $realms = null, ?PanelRegistry $registry = null)
    {
        Trace::$events = [];
        $this->orm = new Orm(new Tenants()->connection, new IdentityMap('hierarchy-test'), Tenancy::tree($tree));
        $this->auth = new AuthContext();
        $this->directory = new Directory($this->orm);
        $this->panels = new PanelContext();

        $container = (new Container())
            ->instance(TenantHosts::class, new Hosts())
            ->instance(PanelContext::class, $this->panels);

        $routes = new RouteRegistry();
        $routes->registerAll([ResellerScripts::class, CustomerScripts::class, CustomerWidens::class, AdminHome::class]);

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $this->auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            tenants: new \Tinvar\Tenancy\Tenancy($container->resolver()),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignIn::class, SessionTenant::class],
            gate: new AllowAllGate(),
            log: function (\Throwable $e): void {
                $this->log[] = $e->getMessage();
            },
            panels: $this->panels,
            container: $container,
            panelRegistry: $registry ?? self::panels(),
            memberships: $this->directory,
            realms: $realms ?? self::realms(),
        );
    }

    public static function panels(): PanelRegistry
    {
        return new PanelRegistry(
            new PanelDefinition('reseller', requires: 'reseller', permission: 'reseller.access', subtree: true),
            new PanelDefinition('customer'),
            new PanelDefinition('admin', guard: AdminGuard::class),
        );
    }

    /**
     * The platform's sign-in host and admin host, listed; the tenants'
     * subdomains of the platform's domain and the reseller's custom domain,
     * looked up. The admin panel is the platform's alone.
     */
    public static function realms(): Realms
    {
        return new Realms(
            Realm::platform('https://id.platform.test'),
            [Realm::platform('https://admin.platform.test', panel: 'admin')],
            static fn (string $host): ?Realm => match ($host) {
                'reseller.platform.test' => Realm::platform('https://reseller.platform.test', 'reseller'),
                'acme.platform.test' => Realm::platform('https://acme.platform.test', 'acme'),
                'other.platform.test' => Realm::platform('https://other.platform.test', 'other'),
                'reseller.example' => Realm::domain('https://reseller.example', 'reseller', 'customer'),
                default => null,
            },
            platformPanels: ['admin'],
        );
    }

    /** @param array<string, string> $headers */
    public function get(string $path, string $host, ?string $user = null, array $headers = []): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['host' => $host, 'accept' => 'application/json', ...($user === null ? [] : ['x-user' => $user]), ...$headers]),
            static fn (LoadedRoute $loaded): Response => Response::make(200, json_encode(
                $loaded->page === null ? [] : get_object_vars($loaded->page),
                JSON_THROW_ON_ERROR,
            )),
        );
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
