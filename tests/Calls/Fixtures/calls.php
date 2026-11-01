<?php

declare(strict_types=1);

namespace Tinvar\Tests\Calls\Fixtures;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\ServiceCall;
use Tinvar\Attributes\Tool;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelDefinition;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\UserLookup;
use Tinvar\Calls\CallRunner;
use Tinvar\Data\Data;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Services\ServiceCalls;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tools\ToolCalls;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

const KEY = 'calls-test-key-of-thirty-two-bytes!!';

final class User implements Authenticatable
{
    public function __construct(public readonly string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Users by id, each on an epoch a test can move. */
final class Users implements UserLookup
{
    /** @var array<string, string> id => epoch */
    public array $epochs = [];

    public function find(string $id): ?Authenticatable
    {
        return isset($this->epochs[$id]) ? new User($id) : null;
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs[$user->authIdentifier()] ?? '';
    }
}

/** What the calls' own tests mark callable, beside a tool or a service call. */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class Exposed {}

/** Staff hold the admin panel, with the permissions a test gives them. */
final class Staff implements Memberships
{
    /** @var array<string, list<string>> id => permissions */
    public array $members = [];

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        $permissions = $this->members[$user->authIdentifier()] ?? null;

        return $panel === 'admin' && $permissions !== null ? new Membership('', $permissions) : null;
    }
}

/** Anyone may view a rack; a rack named "locked" may be renamed by nobody; the warehouse may rename. */
final class RackPolicy
{
    public function view(?Authenticatable $user, Rack $rack): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, Rack $rack): bool
    {
        return $user !== null && $rack->name !== 'locked' && $user->authIdentifier() !== 'mallory';
    }
}

final readonly class RackData extends Data
{
    public function __construct(public string $id, public string $name) {}
}

final readonly class RenameRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(20)]
        public string $name,
    ) {}
}

final class Ran
{
    /** @var list<string> */
    public static array $bodies = [];

    /** @var list<string> the tenant each rename ran in */
    public static array $tenants = [];
}

final class RackActions
{
    public function __construct(private readonly Orm $orm) {}

    #[Action(name: 'racks.rename'), Authorize('update', 'rack'), Tool('Renames a rack.'), ServiceCall('warehouse'), Exposed]
    public function rename(Rack $rack, RenameRequest $request): ActionResult
    {
        Ran::$bodies[] = 'rename';
        Ran::$tenants[] = $this->orm->tenant();
        $rack->name = $request->name;
        $this->orm->save($rack);

        return ActionResult::ok(new RackData($rack->id, $rack->name))->flash(['message' => 'Renamed.']);
    }

    /** Not a tool, nor a service's: an action like any other. */
    #[Action(name: 'racks.scrap'), Authorize('update', 'rack')]
    public function scrap(Rack $rack): ActionResult
    {
        Ran::$bodies[] = 'scrap';

        return ActionResult::ok();
    }
}

#[Panel('admin', permission: 'racks.audit')]
final class AuditActions
{
    #[Action(name: 'racks.audit'), Authorize('view', 'rack'), Tool('Audits a rack.'), ServiceCall('warehouse')]
    public function audit(Rack $rack): ActionResult
    {
        Ran::$bodies[] = 'audit';

        return ActionResult::ok(new RackData($rack->id, $rack->name));
    }
}

#[Query(name: 'racks.show'), Authorize('view', 'rack'), Tool('Shows a rack.', name: 'show_rack'), ServiceCall('warehouse', 'carrier'), Exposed]
final class ShowRack
{
    public function __invoke(Rack $rack): RackData
    {
        Ran::$bodies[] = 'show';

        return new RackData($rack->id, $rack->name);
    }
}

/** Held to the operations desk's host by its own #[Domain] (ADR 0149). */
#[\Tinvar\Attributes\Domain('ops.{root}')]
final class LabelActions
{
    #[Action(name: 'racks.label'), Authorize('view', 'rack'), Tool('Labels a rack.'), ServiceCall('warehouse')]
    public function label(Rack $rack): ActionResult
    {
        Ran::$bodies[] = 'label';

        return ActionResult::ok(new RackData($rack->id, $rack->name));
    }
}

/** Takes the request, so nothing outside a browser can call it. */
final class NeedsRequest
{
    #[Action(name: 'racks.needs'), Authorize('update', 'rack'), Tool('Needs a request.')]
    public function needs(Rack $rack, \Tinvar\Http\Request $request): ActionResult
    {
        return ActionResult::ok();
    }
}

/** A check that runs around an HTTP request: a recent password, say. */
final class RecentPassword implements \Tinvar\Http\Middleware
{
    public function handle(\Tinvar\Http\Request $request, \Closure $next): \Tinvar\Http\Response
    {
        return $next($request);
    }
}

/** Declares its own middleware, which a tool call could not run. */
final class GuardedActions
{
    #[Action(name: 'racks.guarded'), Authorize('update', 'rack'), \Tinvar\Attributes\Middleware(RecentPassword::class), Tool('Guarded.')]
    public function guarded(Rack $rack): ActionResult
    {
        return ActionResult::ok();
    }
}

/**
 * Everything a call needs, over the racks fixture: registries, the runners
 * a browser's calls go through, the tools and the service calls.
 */
final class Harness
{
    public Fixture $fixture;
    public Orm $orm;
    public AuthContext $auth;
    public PanelContext $panels;
    public Users $users;
    public Staff $staff;
    public ActionRegistry $actions;
    public QueryRegistry $queries;
    public EntryContext $entry;
    public BuildRegistry $builds;
    public CallRunner $runner;

    /**
     * The tools and the service calls, each made when a test first asks
     * for it: a calls test, and each module's own run, loads neither
     * (ADR 0114).
     */
    public ToolCalls $tools {
        get => $this->tools ??= new ToolCalls(
            ToolCalls::discover($this->actions, $this->queries, $this->entry),
            $this->runner,
            ToolCalls::grants(KEY, $this->builds, fn (): int => $this->now),
            $this->auth,
            $this->orm,
            $this->panels,
            $this->staff,
            $this->users,
            hosts: $this->hosts,
        );
    }

    public ServiceCalls $services {
        get => $this->services ??= new ServiceCalls(
            ServiceCalls::discover($this->actions, $this->queries, $this->entry),
            $this->runner,
            ServiceCalls::tokens(KEY, $this->builds, fn (): int => $this->now),
            $this->auth,
            $this->orm,
            $this->panels,
            $this->staff,
            $this->users,
            $this->spent,
            fn (): int => $this->now,
            $this->hosts,
        );
    }

    /** The hosts a call is held to; given only to a harness built with them. */
    public ?\Tinvar\Calls\CallHosts $hosts = null;

    /** The application's hosts, when built with them: shop.test, ops.shop.test, and admin.shop.test for the admin panel. */
    public ?\Tinvar\Routing\Domains $domains = null;

    /** The host of the request being answered; null outside one, as in a job. */
    public ?\Tinvar\Routing\HostMatch $inHand = null;
    public int $now = 1_800_000_000;

    /** Where single-use service context tokens are spent (ADR 0119). */
    public \Tinvar\Http\ArrayNonceStore $spent;

    public function __construct(?BuildRegistry $builds = null, bool $hosts = false)
    {
        $this->spent = new \Tinvar\Http\ArrayNonceStore();
        Ran::$bodies = [];
        Ran::$tenants = [];
        $this->fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
        $this->orm = new Orm($this->fixture->connection, new IdentityMap('calls-test'));
        $this->auth = new AuthContext();
        $this->panels = new PanelContext();
        $this->users = new Users();
        $this->staff = new Staff();
        $orm = $this->orm;
        $resolver = static fn (string $class): object => $class === RackActions::class ? new RackActions($orm) : new $class();

        $this->actions = new ActionRegistry($resolver);
        $this->actions->registerAll([RackActions::class, AuditActions::class, ...($hosts ? [LabelActions::class] : [])]);
        $this->queries = new QueryRegistry($resolver);
        $this->queries->registerAll([ShowRack::class]);

        $registry = new PanelRegistry(new PanelDefinition('admin', domain: $hosts ? 'admin.{root}' : null));
        if ($hosts) {
            $this->domains = new \Tinvar\Routing\Domains(['root' => 'shop.test'], patterns: ['ops.{root}', 'admin.{root}']);
            $this->hosts = new \Tinvar\Calls\CallHosts($this->domains, fn (): ?\Tinvar\Routing\HostMatch => $this->inHand);
        }
        $this->entry = new EntryContext($this->orm, $resolver, $this->panels, $registry, $this->staff, $this->auth);
        $gate = new PolicyGate(new PolicyRegistry()->register(Rack::class, RackPolicy::class), $this->auth);
        $this->runner = new CallRunner(
            new ActionRunner($this->actions, $this->orm, $gate, new \Tinvar\Validation\Validator($this->orm)),
            new QueryRunner($this->queries, $this->orm, $gate, panels: fn (string $name): ?string => $this->entry->queryRefusal($this->queries, $name)),
        );

        $this->builds = $builds ?? new BuildRegistry('build-1');
    }

    public function rack(int $index = 0): string
    {
        return $this->fixture->ids['racks'][$index];
    }

    public function rackName(int $index = 0): string
    {
        return (string) $this->fixture->connection->select('SELECT name FROM racks WHERE id = ?', [$this->rack($index)])[0]['name'];
    }

    /**
     * Runs $work signed in as $user on $epoch, which the lookup also holds.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function as(string $user, \Closure $work, string $epoch = 'e1'): mixed
    {
        $this->users->epochs[$user] ??= $epoch;

        return $this->auth->actingAs(new User($user), $work, $epoch);
    }

    /**
     * Runs $work as a request on $host would: with it in hand.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function on(string $host, \Closure $work): mixed
    {
        \assert($this->domains !== null);
        [$before, $this->inHand] = [$this->inHand, $this->domains->match($host) ?? throw new \LogicException("{$host} is not served.")];
        try {
            return $work();
        } finally {
            $this->inHand = $before;
        }
    }

    /**
     * Runs $work as the warehouse's service (warehouse.php).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function asWarehouse(\Closure $work, string $name = 'warehouse'): mixed
    {
        return $this->auth->actingAs(new Warehouse($name), $work);
    }
}
