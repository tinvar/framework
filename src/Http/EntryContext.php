<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Auth\Violation;
use Tinvar\Channels\ChannelRegistry;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\Middleware\EnterPanel;
use Tinvar\Http\Middleware\EnterTenant;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\Middleware\RequirePlatformHost;
use Tinvar\Identity\Realms;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\TenantClaim;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteRegistry;

/**
 * What an entry point's declarations put around it before its own
 * middleware runs: the tenant its #[TenantFrom] names, as the registered
 * tenancy reads it (TenantResolver), then the panel its #[Panel] or its
 * namespace puts it in, with that panel's stack.
 *
 * One object, handed to every path that runs an entry point -- a page, its
 * fragments, a re-render after an action, an action, a query over HTTP,
 * /_bootstrap -- because the alternative is one copy of this per path, and
 * this codebase has watched two copies of one check drift apart more than
 * once (TokenClaims says how). A path that ran a route without it would run
 * that route as no tenant, outside its panel, past its guard.
 *
 * The order is the spec's: the tenant first, since a membership is held in
 * one; then the panel, its guard, the membership check, and the rest of the
 * panel's stack -- MFA, network allowlist, email verification -- in the
 * order its definition gives them (see PanelDefinition). Ahead of them all,
 * for a panel only the platform's hosts may carry, the host itself
 * (RequirePlatformHost).
 */
final readonly class EntryContext
{
    /**
     * @param \Closure(class-string): object $resolver builds tenant sources and a
     *        panel's middleware, as it builds an entry point's own
     * @param Memberships|null $memberships the application's membership model; given,
     *        every request into a panel needs a membership of it
     * @param Realms|null $realms the application's hosts; given, an entry point in a panel
     *        only the platform's hosts may carry is refused on any other
     * @param TenantResolver|null $tenants the tenancy registered (tinvar/tenancy); without it,
     *        an entry point declaring #[TenantFrom] is refused
     */
    public function __construct(
        private Orm $orm,
        private \Closure $resolver,
        private ?PanelContext $panels = null,
        private ?PanelRegistry $registry = null,
        private ?Memberships $memberships = null,
        private ?AuthContext $auth = null,
        private ?Realms $realms = null,
        private ?TenantResolver $tenants = null,
    ) {}

    /**
     * The middleware that goes outermost in an entry point's own stack.
     *
     * @param string|null $tenantSource what its #[TenantFrom] names
     * @param PanelBinding|null $panel the panel it resolved to; see panelFor()
     * @return list<Middleware>
     */
    public function stackFor(?string $tenantSource, ?PanelBinding $panel = null): array
    {
        // The host before anything else, the tenant included: a page of the
        // admin panel on a tenant's domain is not there, whoever asks.
        $stack = $panel !== null && $this->realms?->isPlatformPanel($panel->name) === true
            ? [new RequirePlatformHost($this->realms, $panel->name)]
            : [];
        if ($tenantSource !== null) {
            $stack[] = new EnterTenant($this->orm, $this->tenants($tenantSource), $tenantSource);
        }
        if ($panel === null) {
            return $stack;
        }

        $definition = $this->registry?->definition($panel->name) ?? throw self::undefined($panel->name);
        if ($this->panels === null) {
            throw new \LogicException("An entry point is in panel '{$panel->name}', and the Kernel was given no PanelContext to put it in.");
        }

        $stack[] = new EnterPanel($this->panels, $panel->name);
        if ($definition->guard !== null) {
            $stack[] = $this->middleware($definition->guard);
        }

        if ($this->memberships !== null && $this->auth !== null) {
            $stack[] = new RequirePanelMembership($this->auth, $this->memberships, $this->orm, $panel, $this->panels);
        } elseif ($panel->narrows()) {
            // Carried and not enforced is what this replaced. check() refuses
            // it when the Kernel is built; this is for a route registered after.
            throw self::unenforced($panel->name);
        }

        foreach ($definition->middleware as $class) {
            $stack[] = $this->middleware($class);
        }

        return $stack;
    }

    /**
     * The panel an entry point is in -- by its #[Panel], or else by the
     * namespace of its class -- with everything its declarations and the
     * panel's definition require of a membership; null for none.
     *
     * @param list<Panel> $declared every #[Panel] it carries, outermost first
     * @param class-string $class whose namespace decides when nothing is declared
     */
    public function panelFor(array $declared, string $class): ?PanelBinding
    {
        $binding = PanelBinding::of($declared);
        if ($binding === null) {
            $byNamespace = $this->registry?->panelOf($class);
            $binding = $byNamespace === null ? null : new PanelBinding($byNamespace);
        }
        if ($binding === null) {
            return null;
        }

        return $binding->within($this->registry?->definition($binding->name) ?? throw self::undefined($binding->name));
    }

    /**
     * The stack for a route chain: its tenant, then its panel's.
     *
     * @return list<Middleware>
     */
    public function stackForChain(RouteChain $chain): array
    {
        return $this->stackFor($chain->tenantSource(), $this->panelForChain($chain));
    }

    /** A route chain's panel: its page's and layouts' declarations, or its page's namespace. */
    public function panelForChain(RouteChain $chain): ?PanelBinding
    {
        return $this->panelFor($chain->panels(), $chain->page->class);
    }

    /**
     * The tenant an entry point would run as for $request, without running
     * it: for minting tokens a later request will be held to.
     *
     * @throws HttpException as EnterTenant refuses
     */
    public function tenantFor(?string $tenantSource, Request $request): string
    {
        return $tenantSource === null
            ? $this->orm->tenant()
            : EnterTenant::resolve($this->orm, $this->tenants($tenantSource), $tenantSource, $request);
    }

    /**
     * The tenant claim a token minted for that entry point carries: the
     * tenant of tenantFor(), as TenantClaim binds a token to it.
     *
     * @throws HttpException as EnterTenant refuses
     */
    public function tenantClaimFor(?string $tenantSource, Request $request): string
    {
        return TenantClaim::of($this->orm, $this->tenantFor($tenantSource, $request));
    }

    /**
     * Refuses, when the Kernel is built, a #[Panel] that names no defined
     * panel, and a `requires:` or `permission:` -- declared or defined --
     * with no Memberships to hold it to. Either would otherwise be a panel
     * that looked guarded and was not, found by whoever first reached it.
     * So is a panel reaching its tenant's subtree with no tenant tree to
     * reach across, and a platform-only panel the application does not
     * define -- a typo there would leave the real one on every host. And a
     * #[TenantFrom] with no tenancy registered to read it, or naming a
     * source the tenancy cannot read: the entry point would run as no tenant
     * while its author believed it ran as one.
     */
    public function check(
        RouteRegistry $routes,
        ActionRegistry $actions,
        QueryRegistry $queries,
        ?EndpointRegistry $endpoints = null,
        ?ChannelRegistry $channels = null,
    ): void {
        $declared = [];
        $sources = [];
        foreach ([...$routes->layouts(), ...$routes->pages()] as $route) {
            $declared[$route->class] = $route->panels;
            $sources[$route->class] = $route->tenantFrom;
        }
        foreach ($endpoints?->all() ?? [] as $endpoint) {
            $declared[$endpoint->where()] = $endpoint->panels;
            $sources[$endpoint->where()] = $endpoint->tenantFrom;
        }
        foreach ($channels?->all() ?? [] as $channel) {
            $declared[$channel->where()] = $channel->panels;
            $sources[$channel->where()] = $channel->tenantFrom;
        }
        foreach ($actions->all() as $action) {
            $declared[$action->where()] = $action->panels;
            $sources[$action->where()] = $action->tenantFrom;
        }
        foreach ($queries->names() as $name) {
            $declared[$queries->classFor($name) ?? $name] = $queries->panelsFor($name);
            $sources[$queries->classFor($name) ?? $name] = $queries->tenantSourceFor($name);
        }

        foreach ($sources as $where => $source) {
            if ($source === null) {
                continue;
            }
            try {
                $this->tenants($source)->check($source);
            } catch (\LogicException|\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("{$where}: {$e->getMessage()}", 0, $e);
            }
        }

        foreach ($declared as $where => $panels) {
            foreach ($panels as $panel) {
                if ($this->registry?->has($panel->name) !== true) {
                    throw new \InvalidArgumentException("{$where} is in panel '{$panel->name}', which the application does not define.");
                }
                if ($this->memberships === null && ($panel->requires !== null || $panel->permission !== null)) {
                    throw new \InvalidArgumentException("{$where}: " . self::unenforced($panel->name)->getMessage());
                }
            }
        }

        foreach ($this->registry?->all() ?? [] as $definition) {
            if ($this->memberships === null && ($definition->requires !== null || $definition->permission !== null)) {
                throw new \InvalidArgumentException(self::unenforced($definition->name)->getMessage());
            }
            if ($definition->subtree && $this->orm->tenants() === null) {
                throw new \InvalidArgumentException(
                    "Panel '{$definition->name}' reaches its tenant's subtree, and the Orm was given no tenant tree to reach across.",
                );
            }
        }

        // With no panels defined, nothing can be in one: no entry point to
        // keep off a host, and nothing a typo could leave out.
        $registry = $this->registry;
        $platformPanels = $registry === null ? [] : $this->realms?->platformPanels() ?? [];
        foreach ($platformPanels as $panel) {
            if (!$registry->has($panel)) {
                throw new \InvalidArgumentException(
                    "The realms keep panel '{$panel}' to the platform's hosts, and the application defines no such panel.",
                );
            }
        }
    }

    /**
     * Refuses, when the Kernel is built, a route whose query allowlist names
     * a query its own panel does not cover.
     *
     * A query called from a render runs in its page's request context --
     * its page's panel, behind its page's stack -- not behind its own, which
     * only an HTTP call to /_query runs. So a page in a looser panel, or in
     * none, that may call a query declared for a stricter one would run it
     * past every check the query declared. The route's binding has to cover
     * the query's: the same panel, and every tenant kind and permission the
     * query needs among those the route was held to.
     *
     * And the other way round, the spec's panel-binding rule for queries
     * (BuildRules::PANEL_ONLY_QUERY_MUST_BE_BOUND): "an action or query
     * reachable only from admin-panel pages with no panel binding fails the
     * build." A query with no binding of its own, which only pages in a
     * panel may call, was written for those pages -- and over /_query it
     * runs behind no panel's stack at all, where every page using it runs
     * behind one. A query some route in no panel may also call is not
     * theirs alone, and one no route lists is reached over HTTP only; both
     * pass.
     *
     * @param \Closure(string): list<string> $allowedQueries the route's query allowlist, by route name
     */
    public function checkAllowlists(RouteRegistry $routes, QueryRegistry $queries, \Closure $allowedQueries): void
    {
        // Each unbound query's callers, by the panel they are in: '' for
        // none, which is enough to pass it.
        /** @var array<string, array<string, list<string>>> $unbound */
        $unbound = [];

        foreach ($routes->pages() as $page) {
            if ($page->name === null) {
                continue;
            }

            $chain = $routes->chainFor($page->name, array_fill_keys($page->parameters, ''));
            $routePanel = $this->panelForChain($chain);

            foreach ($allowedQueries($page->name) as $name) {
                $queryPanel = $this->queryPanel($queries, $name);
                if ($queryPanel === null && $queries->classFor($name) !== null) {
                    $unbound[$name][$routePanel->name ?? ''][] = $page->name;
                }
                if ($queryPanel !== null && ($routePanel === null || !$routePanel->covers($queryPanel))) {
                    throw new \InvalidArgumentException(\sprintf(
                        "Route '%s' may call query '%s', which is in panel '%s'%s; the route is %s. A query called "
                        . 'from a render runs behind its page\'s panel, not its own, so the page has to be held to '
                        . 'everything the query is.',
                        $page->name,
                        $name,
                        $queryPanel->name,
                        self::requirements($queryPanel),
                        $routePanel === null ? 'in no panel' : "in panel '{$routePanel->name}'" . self::requirements($routePanel),
                    ));
                }
            }
        }

        foreach ($unbound as $name => $callers) {
            if (isset($callers[''])) {
                continue;
            }

            $panels = array_keys($callers);
            $where = implode(', ', array_map(
                static fn (string $panel, array $pages): string => "'{$panel}' (" . implode(', ', $pages) . ')',
                $panels,
                $callers,
            ));
            // One panel to bind it to, or several, which one query cannot be in.
            $fix = \count($panels) === 1
                ? "Bind it with #[Panel('{$panels[0]}')], or move it into that panel's namespace."
                : 'A query is in one panel, so split it, one per panel, each bound with #[Panel] or moved into '
                    . "that panel's namespace.";

            throw new \InvalidArgumentException((string) new Violation(
                BuildRules::PANEL_ONLY_QUERY_MUST_BE_BOUND,
                "query '{$name}' (" . $queries->classFor($name) . ')',
                "has no panel binding, and every route that may call it is in a panel: {$where}. Over /_query "
                . "it runs behind no panel's stack -- no guard, no membership check, none of the panel's "
                . "middleware -- where every page using it runs behind one. {$fix}",
            ));
        }
    }

    /**
     * Why a call to query $name may not run in the request as it stands, or
     * null when it may: the render path's half of checkAllowlists(), for a
     * route registered after the Kernel, an allowlist the Kernel was never
     * shown, or a panel set by the application's own middleware.
     *
     * A query declaring a panel runs only in that panel -- its stack, guard
     * and MFA included, never ran for a request in another, and a
     * membership alone cannot stand in for them. In it, a request whose
     * membership was checked against a binding covering the query's runs it
     * as is; one that was not -- the query needs a permission its page did
     * not -- has the membership checked again, against the query's binding.
     */
    public function queryRefusal(QueryRegistry $queries, string $name): ?string
    {
        $queryPanel = $this->queryPanel($queries, $name);
        if ($queryPanel === null) {
            return null;
        }

        $current = $this->panels?->currentOrNull();
        if ($current !== $queryPanel->name) {
            return "query '{$name}' is in panel '{$queryPanel->name}', and the request is in " . ($current === null ? 'none' : "panel '{$current}'");
        }

        // Without Memberships a binding cannot narrow (check() refuses it),
        // so being in the panel -- behind its stack -- is all it asks.
        if ($this->memberships === null || $this->auth === null) {
            return $queryPanel->narrows() ? self::unenforced($queryPanel->name)->getMessage() : null;
        }

        // In the panel, so there is a context to have been granted it in.
        if ($this->panels->granted()?->covers($queryPanel) === true) {
            return null;
        }

        try {
            RequirePanelMembership::verify($this->auth, $this->memberships, $this->orm, $queryPanel);
        } catch (HttpException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** A query's panel binding, by its #[Panel] or its namespace; null for none or an unknown name. */
    private function queryPanel(QueryRegistry $queries, string $name): ?PanelBinding
    {
        $class = $queries->classFor($name);

        return $class === null ? null : $this->panelFor($queries->panelsFor($name), $class);
    }

    private static function requirements(PanelBinding $panel): string
    {
        $needs = [...array_map(static fn (string $k): string => "a {$k} tenant", $panel->kinds), ...array_map(
            static fn (string $p): string => "'{$p}'",
            $panel->permissions,
        )];

        return $needs === [] ? '' : ' needing ' . implode(', ', $needs);
    }

    /** The tenancy that reads $source, or the refusal of an entry point declaring one with none registered. */
    private function tenants(string $source): TenantResolver
    {
        return $this->tenants ?? throw new \LogicException(
            "An entry point declares #[TenantFrom({$source})], and no tenancy is registered to read it: require "
            . 'tinvar/tenancy and give the Kernel its Tenancy, or the entry point would run as no tenant.',
        );
    }

    /** @param class-string<Middleware> $class */
    private function middleware(string $class): Middleware
    {
        $middleware = ($this->resolver)($class);
        if (!$middleware instanceof Middleware) {
            throw new \LogicException("The resolver built {$class} as " . get_debug_type($middleware) . ', not middleware.');
        }

        return $middleware;
    }

    private static function undefined(string $panel): \LogicException
    {
        return new \LogicException("Panel '{$panel}' is declared, and the application does not define it: it would run with no guard and no stack.");
    }

    private static function unenforced(string $panel): \LogicException
    {
        return new \LogicException(
            "Panel '{$panel}' requires a tenant kind or a permission, and the application supplied no Memberships to hold it to.",
        );
    }
}
