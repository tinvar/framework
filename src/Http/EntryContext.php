<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\Middleware\EnterPanel;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\Middleware\ResolveTenant;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tenancy\TenantSource;

/**
 * What an entry point's declarations put around it before its own
 * middleware runs: the tenant its #[TenantFrom] names, then the panel its
 * #[Panel] or its namespace puts it in, with that panel's stack.
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
 * order its definition gives them (see PanelDefinition).
 */
final readonly class EntryContext
{
    /**
     * @param \Closure(class-string): object $resolver builds tenant sources and a
     *        panel's middleware, as it builds an entry point's own
     * @param Memberships|null $memberships the application's membership model; given,
     *        every request into a panel needs a membership of it
     */
    public function __construct(
        private Orm $orm,
        private \Closure $resolver,
        private ?PanelContext $panels = null,
        private ?PanelRegistry $registry = null,
        private ?Memberships $memberships = null,
        private ?AuthContext $auth = null,
    ) {}

    /**
     * The middleware that goes outermost in an entry point's own stack.
     *
     * @param class-string<TenantSource>|null $tenantSource what its #[TenantFrom] names
     * @param PanelBinding|null $panel the panel it resolved to; see panelFor()
     * @return list<Middleware>
     */
    public function stackFor(?string $tenantSource, ?PanelBinding $panel = null): array
    {
        $stack = $tenantSource === null ? [] : [new ResolveTenant($this->orm, $this->source($tenantSource))];
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
     * @param class-string<TenantSource>|null $tenantSource
     * @throws HttpException as ResolveTenant refuses
     */
    public function tenantFor(?string $tenantSource, Request $request): string
    {
        return $tenantSource === null
            ? $this->orm->tenant()
            : ResolveTenant::resolve($this->orm, $this->source($tenantSource), $request);
    }

    /**
     * Refuses, when the Kernel is built, a #[Panel] that names no defined
     * panel, and a `requires:` or `permission:` -- declared or defined --
     * with no Memberships to hold it to. Either would otherwise be a panel
     * that looked guarded and was not, found by whoever first reached it.
     */
    public function check(RouteRegistry $routes, ActionRegistry $actions, QueryRegistry $queries): void
    {
        $declared = [];
        foreach ([...$routes->layouts(), ...$routes->pages()] as $route) {
            $declared[$route->class] = $route->panels;
        }
        foreach ($actions->all() as $action) {
            $declared[$action->where()] = $action->panels;
        }
        foreach ($queries->names() as $name) {
            $declared[$queries->classFor($name) ?? $name] = $queries->panelsFor($name);
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
     * @param \Closure(string): list<string> $allowedQueries the route's query allowlist, by route name
     */
    public function checkAllowlists(RouteRegistry $routes, QueryRegistry $queries, \Closure $allowedQueries): void
    {
        foreach ($routes->pages() as $page) {
            if ($page->name === null) {
                continue;
            }

            $chain = $routes->chainFor($page->name, array_fill_keys($page->parameters, ''));
            $routePanel = $this->panelForChain($chain);

            foreach ($allowedQueries($page->name) as $name) {
                $queryPanel = $this->queryPanel($queries, $name);
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

    /** @param class-string<TenantSource> $class */
    private function source(string $class): TenantSource
    {
        $source = ($this->resolver)($class);
        if (!$source instanceof TenantSource) {
            throw new \LogicException("The resolver built {$class} as " . get_debug_type($source) . ', not a tenant source.');
        }

        return $source;
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
