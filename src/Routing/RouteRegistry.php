<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\EncryptHistory;
use Tinvar\Attributes\ErrorPage;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Data\Data;
use Tinvar\Http\HttpError;
use Tinvar\Http\Request;
use Tinvar\Support\Str;

/**
 * Routes come from attributes on classes. There are no route files.
 *
 * Compilation happens once per worker: production never reflects, matching only
 * walks precompiled segments.
 */
final class RouteRegistry
{
    /** @var list<CompiledRoute> */
    private array $pages = [];

    /** @var list<CompiledRoute> */
    private array $layouts = [];

    /** @var array<string, CompiledRoute> */
    private array $byName = [];

    /** @var array<string, list<CompiledRoute>> page template => its layouts */
    private array $layoutCache = [];

    /** @var array<string, CompiledRoute> layout key => the layout that owns it */
    private array $byLayoutKey = [];

    private bool $sorted = false;

    /** @var array<int, CompiledRoute> status => the #[ErrorPage] shown for it */
    private array $errorPages = [];

    /**
     * Marks an error page declared within no path, so under no layout at
     * all, which `/` -- wrapped by a root layout -- is not. No layout's key
     * can be it: a view path holds no NUL.
     */
    private const string NO_LAYOUTS = "\0";

    /** @param class-string $class */
    public function register(string $class): CompiledRoute
    {
        $reflection = new \ReflectionClass($class);

        $pageAttribute = $reflection->getAttributes(Page::class)[0] ?? null;
        $layoutAttribute = $reflection->getAttributes(Layout::class)[0] ?? null;
        $errorAttribute = $reflection->getAttributes(ErrorPage::class)[0] ?? null;

        if ($errorAttribute !== null) {
            if ($pageAttribute !== null || $layoutAttribute !== null) {
                throw new RoutingException("{$class} is an #[ErrorPage] and a route at once; an error page has no URL of its own.");
            }

            return $this->registerErrorPage($class, $errorAttribute->newInstance());
        }

        if ($pageAttribute === null && $layoutAttribute === null) {
            throw RoutingException::missingAttribute($class);
        }

        $kind = $pageAttribute !== null ? RouteKind::Page : RouteKind::Layout;
        $declaration = ($pageAttribute ?? $layoutAttribute)->newInstance();
        $name = $declaration instanceof Page ? $declaration->name : null;

        // Both reach generated TypeScript -- PageProps<'racks/show'>,
        // route('racks.show') -- so a quote, a backslash or a newline in
        // either would be written into source. Refused here, where the
        // declaration is read, rather than left for codegen to escape.
        if (!Str::isViewPath($declaration->view)) {
            throw RoutingException::badView($class, $declaration->view);
        }
        if ($name !== null && !Str::isDottedIdentifier($name)) {
            throw RoutingException::badName($class, $name);
        }

        // Off the class itself, as an opt-out is read (see EncryptHistory).
        // On a layout it would be a declaration nothing reads, which is
        // refused here rather than left to look like it opted pages out.
        $encryptHistory = ($reflection->getAttributes(EncryptHistory::class)[0] ?? null)?->newInstance()->encrypt;
        if ($encryptHistory !== null && $kind === RouteKind::Layout) {
            throw RoutingException::encryptHistoryOnLayout($class);
        }

        // Compiled once; parameter names are derived from the same pass.
        $segments = self::compile($declaration->path);

        $route = new CompiledRoute(
            kind: $kind,
            class: $class,
            template: self::normalizeTemplate($declaration->path),
            view: $declaration->view,
            name: $name,
            segments: $segments,
            parameters: self::parametersOf($declaration->path, $segments),
            // The class's declarations and its loader's, read the way
            // BuildRules reads them. Only the class's used to reach the
            // chain, so an #[Authorize] or #[Middleware] written on __invoke
            // passed the build rules and never ran.
            middleware: Middleware::declaredOn($class, '__invoke'),
            checks: Authorize::declaredOn($class, '__invoke'),
            layoutKey: $kind === RouteKind::Layout ? self::layoutKeyOf($declaration->view) : '',
            encryptHistory: $encryptHistory,
            tenantFrom: self::tenantFromOf($class),
            panels: self::panelsOf($class),
        );

        // A loader parameter the router cannot bind is a developer error that
        // would otherwise surface as a TypeError on the first request.
        Binder::assertBindable($route);

        // The security model says every model-typed input of every routable
        // entry point is covered "by BuildRules AND at registration". Only the
        // second half survives someone not running the build step, and until
        // now only ActionRegistry had it: a #[Page('/racks/{rack}')] whose
        // loader took Rack $rack and declared no check registered happily and
        // served its data under a gate that denies everything.
        $violations = (new BuildRules())->check([$class]);
        if ($violations !== []) {
            throw RoutingException::buildRules($class, $violations);
        }

        $this->add($route);

        return $route;
    }

    /**
     * @param list<class-string> $classes
     * @return list<CompiledRoute>
     */
    public function registerAll(array $classes): array
    {
        return array_map($this->register(...), $classes);
    }

    private function add(CompiledRoute $route): void
    {
        // Compared by shape, not by spelling: `/racks/{rack}` and
        // `/racks/{id}` match exactly the same paths, and with both accepted
        // the one that answered depended on registration order -- a page with
        // its own checks and middleware silently shadowing another.
        $existing = $route->kind === RouteKind::Page ? $this->pages : $this->layouts;
        foreach ($existing as $other) {
            if ($other->shape() === $route->shape()) {
                throw RoutingException::duplicate($route->template, $other->class, $route->class);
            }
        }

        // Every layout and page pair this route forms with one already
        // registered. Whichever of the two registers second is the one
        // refused, so the order they arrive in does not matter -- and a
        // typo fails registration rather than the first request under it.
        if ($route->kind === RouteKind::Page) {
            foreach ($this->layouts as $layout) {
                self::assertParameterNamesAgree($layout, $route);
                self::assertTenantSourcesAgree($layout, $route);
                self::assertPanelsAgree($layout, $route);
            }
        } else {
            foreach ($this->pages as $page) {
                self::assertParameterNamesAgree($route, $page);
                self::assertTenantSourcesAgree($route, $page);
                self::assertPanelsAgree($route, $page);
            }
            // A group inside a group: a page under both runs as one tenant,
            // in one panel, so the two have to agree whether or not a page
            // exists yet.
            foreach ($this->layouts as $layout) {
                self::assertTenantSourcesAgree($layout, $route);
                self::assertTenantSourcesAgree($route, $layout);
                self::assertPanelsAgree($layout, $route);
                self::assertPanelsAgree($route, $layout);
            }
        }

        if ($route->kind === RouteKind::Layout) {
            $owner = $this->byLayoutKey[$route->layoutKey] ?? null;
            if ($owner !== null) {
                throw RoutingException::duplicateLayoutKey($route->layoutKey, $owner->class, $route->class);
            }
            $this->byLayoutKey[$route->layoutKey] = $route;
        }

        if ($route->name !== null) {
            if (isset($this->byName[$route->name])) {
                throw RoutingException::duplicateName(
                    $route->name,
                    $this->byName[$route->name]->class,
                    $route->class,
                );
            }
            $this->byName[$route->name] = $route;
        }

        if ($route->kind === RouteKind::Page) {
            $this->pages[] = $route;
        } else {
            $this->layouts[] = $route;
        }

        $this->sorted = false;
        $this->layoutCache = [];
    }

    /** Matches a path to a page and the layouts wrapping it. */
    public function match(string $path): ?RouteChain
    {
        $this->sort();

        foreach ($this->pages as $page) {
            $parameters = $page->match($path);
            if ($parameters === null) {
                continue;
            }

            // Only the layout list is cached. A RouteChain carries the
            // parameters of the request that produced it, so caching the whole
            // chain would hand every later request for this template the first
            // request's parameters -- in a worker that is a cross-request data
            // leak and an authorization bypass, since the chain's checks would
            // run against someone else's row.
            $layouts = $this->layoutCache[$page->template] ??= $this->layoutsFor($page);

            return new RouteChain(page: $page, layouts: $layouts, parameters: $parameters);
        }

        return null;
    }

    /**
     * The chain for a page identified by NAME, with parameters given directly.
     *
     * For callers that already know which route they mean -- a deferred
     * fragment token, a post-action re-render -- rather than ones that have a
     * URL to resolve.
     *
     * Going through a URL instead is not the same thing, because the round
     * trip is not identity: build `/sites/{site}` with site="new" and you get
     * `/sites/new`, which forward-matches a more specific static route if one
     * exists. A fragment issued for `sites.show` would then run `sites.new`'s
     * middleware and #[Authorize] chain -- a chain its own page never
     * declared, and possibly an empty one. The spec says "the ORIGINATING
     * route's full middleware, layout and page #[Authorize] chain"; only
     * naming the route gets that.
     *
     * @param array<string, string> $parameters
     */
    public function chainFor(string $name, array $parameters): RouteChain
    {
        $page = $this->named($name);

        // Every parameter the template declares has to be present, or the
        // chain would authorize against a model nobody named.
        foreach ($page->parameters as $parameter) {
            if (!\array_key_exists($parameter, $parameters)) {
                throw RoutingException::missingParameter($name, $parameter);
            }
        }

        return new RouteChain(
            page: $page,
            layouts: $this->layoutCache[$page->template] ??= $this->layoutsFor($page),
            parameters: $parameters,
        );
    }

    /** @return list<CompiledRoute> outermost first */
    private function layoutsFor(CompiledRoute $page): array
    {
        $wrapping = [];

        // Parameter names were checked pair by pair at registration (see
        // assertParameterNamesAgree), so every layout wrapping the page
        // binds what the page calls the same thing.
        foreach ($this->layouts as $layout) {
            if ($layout->wraps($page)) {
                $wrapping[] = $layout;
            }
        }

        usort($wrapping, static fn (CompiledRoute $a, CompiledRoute $b): int => $a->depth() <=> $b->depth());

        return $wrapping;
    }

    /**
     * Refuses a page that names a parameter differently from a layout that
     * wraps it.
     *
     * The layout still wraps the page -- wrapping is positional, so a typo
     * cannot unguard a route -- but it binds by name, and a page at
     * `/sites/{siteId}/edit` under `/sites/{site}` would hand the layout a
     * parameter it never receives. This used to surface on the first request
     * under the pair.
     */
    private static function assertParameterNamesAgree(CompiledRoute $layout, CompiledRoute $page): void
    {
        if (!$layout->wraps($page)) {
            return;
        }

        $mismatch = $layout->parameterNameMismatch($page);
        if ($mismatch !== null) {
            throw RoutingException::parameterNameMismatch(
                $layout->class,
                $layout->template,
                $page->class,
                $page->template,
                $mismatch,
            );
        }
    }

    /**
     * Refuses a route group and a route inside it that name different
     * tenant sources.
     *
     * Which one should win has no good answer: a page that says Signature
     * under a group that says Host was written by someone who believed
     * the page ran as the tenant its link names, and someone else who
     * believed every page in the group ran as its host's. One of them is
     * wrong about whose data the page shows, so neither is chosen.
     */
    private static function assertTenantSourcesAgree(CompiledRoute $outer, CompiledRoute $inner): void
    {
        if ($outer->tenantFrom === null || $inner->tenantFrom === null
            || $outer->tenantFrom === $inner->tenantFrom || !$outer->wraps($inner)) {
            return;
        }

        throw new RoutingException(\sprintf(
            '%s (%s) takes its tenant from %s, and %s (%s) inside it from %s. A route has one tenant source.',
            $outer->class,
            $outer->template,
            $outer->tenantFrom,
            $inner->class,
            $inner->template,
            $inner->tenantFrom,
        ));
    }

    /**
     * Refuses a route group and a route inside it declared in different
     * panels: a page is in one, and whichever lost would be a page its
     * author believed was behind a guard it is not behind.
     */
    private static function assertPanelsAgree(CompiledRoute $outer, CompiledRoute $inner): void
    {
        if ($outer->panels === [] || $inner->panels === [] || !$outer->wraps($inner)) {
            return;
        }

        $theirs = $outer->panels[0]->name;
        $ours = $inner->panels[0]->name;
        if ($theirs === $ours) {
            return;
        }

        throw new RoutingException(\sprintf(
            "%s (%s) is in panel '%s', and %s (%s) inside it in panel '%s'. A route is in one panel.",
            $outer->class,
            $outer->template,
            $theirs,
            $inner->class,
            $inner->template,
            $ours,
        ));
    }

    /**
     * A route's #[Panel] declarations, on its class or its loader, which
     * must name one panel.
     *
     * @param class-string $class
     * @return list<Panel>
     */
    private static function panelsOf(string $class): array
    {
        $panels = Panel::declaredOn($class, '__invoke');

        try {
            PanelBinding::of($panels);
        } catch (\LogicException $e) {
            throw new RoutingException("{$class}: {$e->getMessage()}", 0, $e);
        }

        return $panels;
    }

    /**
     * A route's #[TenantFrom], on its class or its loader; a class that
     * declares two sources is a declaration bug, reported as one.
     *
     * @param class-string $class
     * @return class-string|null
     */
    private static function tenantFromOf(string $class): ?string
    {
        try {
            return TenantFrom::declaredOn($class, '__invoke');
        } catch (\LogicException|\InvalidArgumentException $e) {
            throw new RoutingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * An #[ErrorPage], compiled as a page at the path it sits `within`, and
     * kept apart from the pages: no URL matches it, and no name reaches it.
     *
     * Refused here, rather than on the request that first fails, is anything
     * the error page could not be rendered with. It renders where routing,
     * a middleware or a check has just failed, so it runs no middleware of
     * its own and declares no checks, panel or tenant: there is nothing in
     * place to hold them to. Its layouts' checks still run -- see
     * assertErrorPagesRenderable() for what is asked of them.
     *
     * @param class-string $class
     */
    private function registerErrorPage(string $class, ErrorPage $declaration): CompiledRoute
    {
        if (!Str::isViewPath($declaration->view)) {
            throw RoutingException::badView($class, $declaration->view);
        }

        $within = $declaration->within ?? '/';
        $segments = self::compile($within);
        if (self::parametersOf($within, $segments) !== []) {
            throw new RoutingException(
                "{$class} sits within {$within}, which has parameters. An error page is shown where nothing "
                . 'matched, so there is nothing to fill them from: put it within a path of literal segments.',
            );
        }

        if (Middleware::declaredOn($class, '__invoke') !== [] || Authorize::declaredOn($class, '__invoke') !== []
            || self::panelsOf($class) !== [] || self::tenantFromOf($class) !== null) {
            throw new RoutingException(
                "{$class} declares middleware, a check, a panel or a tenant source. An error page renders where "
                . 'those may be what failed, so it can declare none; its layouts\' checks still run.',
            );
        }

        self::assertErrorLoaderBindable($class);

        $route = new CompiledRoute(
            kind: RouteKind::Page,
            class: $class,
            template: self::normalizeTemplate($within),
            view: $declaration->view,
            name: null,
            segments: $segments,
            parameters: [],
            middleware: [],
            checks: [],
            layoutKey: $declaration->within === null ? self::NO_LAYOUTS : '',
        );

        if ($declaration->statuses === []) {
            throw new RoutingException("{$class} is an #[ErrorPage] for no status at all.");
        }
        foreach ($declaration->statuses as $status) {
            if ($status < 400 || $status > 599) {
                throw new RoutingException("{$class} names " . var_export($status, true) . ' as an error status; an error page is for 400 to 599.');
            }
            if (isset($this->errorPages[$status])) {
                throw new RoutingException("Both {$this->errorPages[$status]->class} and {$class} are the error page for {$status}.");
            }
            $this->errorPages[$status] = $route;
        }

        return $route;
    }

    /**
     * An error page's loader takes the HttpError, the Request, its layouts'
     * Data by type, and anything defaulted -- nothing a path or a model
     * binding would have to supply.
     *
     * @param class-string $class
     */
    private static function assertErrorLoaderBindable(string $class): void
    {
        if (!method_exists($class, '__invoke')) {
            return;
        }

        foreach (new \ReflectionMethod($class, '__invoke')->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $bindable = $name === HttpError::class || $name === Request::class
                || ($name !== null && ($name === Data::class || is_subclass_of($name, Data::class)));
            if (!$bindable && !$parameter->isDefaultValueAvailable()) {
                throw RoutingException::unbindableParameter($class, $parameter->getName(), (string) ($type ?? 'untyped'));
            }
        }
    }

    /**
     * The error page for a status, in the layouts at the path it sits within,
     * or null when the application has none for it. A page for 500 answers
     * any 5xx no other page names: a 502 or a 503 is the server's failure as
     * much as a 500 is.
     */
    public function errorChain(int $status): ?RouteChain
    {
        $page = $this->errorPages[$status] ?? ($status >= 500 ? $this->errorPages[500] ?? null : null);
        if ($page === null) {
            return null;
        }

        $layouts = $page->layoutKey === self::NO_LAYOUTS ? [] : ($this->layoutCache["error:{$page->template}"] ??= $this->layoutsFor($page));

        return new RouteChain(page: $page, layouts: $layouts, parameters: []);
    }

    /**
     * Every #[ErrorPage], once each however many statuses it answers.
     *
     * @return list<CompiledRoute>
     */
    public function errorPages(): array
    {
        $pages = [];
        foreach ($this->errorPages as $page) {
            $pages[$page->class] = $page;
        }

        return array_values($pages);
    }

    /**
     * Refuses an error page whose layouts it could not be rendered in, once
     * every route is registered -- the Kernel asks when it is built, which
     * is when the layouts are all known.
     *
     * A layout running middleware, in a panel or for a tenant needs context
     * an error page cannot establish: it is shown where that context may be
     * what failed, and it runs no middleware. Such a layout's loader may
     * rely on its guard having run, so it is not run without it.
     */
    public function assertErrorPagesRenderable(): void
    {
        foreach (array_unique(array_keys($this->errorPages)) as $status) {
            $chain = $this->errorChain($status);
            if ($chain === null) {
                continue;
            }
            foreach ($chain->layouts as $layout) {
                if ($layout->middleware !== [] || $layout->panels !== [] || $layout->tenantFrom !== null) {
                    throw new RoutingException(
                        "{$chain->page->class} is the error page for {$status}, within {$layout->class}, which runs "
                        . 'middleware, in a panel or for a tenant. An error page runs none of those, so it cannot sit '
                        . 'within that layout: put it within a path whose layouts declare none.',
                    );
                }
            }
        }
    }

    public function named(string $name): CompiledRoute
    {
        return $this->byName[$name] ?? throw RoutingException::unknownName($name);
    }

    /**
     * The route names a revalidation target refers to.
     *
     * The spec writes `revalidate('racks/show')` -- a view path -- and route
     * tokens carry route names, so only the name form ever matched: the
     * spec's own example re-rendered nothing, and said nothing. Both forms
     * are accepted, a route name or the view of named pages. A target that
     * is neither is a typo or a stale reference in the action, and is
     * refused rather than re-rendering nothing in silence.
     *
     * @return list<string>
     */
    public function revalidationTargets(string $target): array
    {
        $names = isset($this->byName[$target]) ? [$target] : [];

        // Only named pages: a route token is minted for nothing else, so an
        // unnamed page's view could never be re-rendered either.
        foreach ($this->pages as $page) {
            if ($page->name !== null && $page->view === $target && !\in_array($page->name, $names, true)) {
                $names[] = $page->name;
            }
        }

        return $names !== [] ? $names : throw RoutingException::unknownRevalidationTarget($target);
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        return $this->named($name)->url($parameters);
    }

    /** @return list<CompiledRoute> */
    public function pages(): array
    {
        $this->sort();

        return $this->pages;
    }

    /** @return list<CompiledRoute> */
    public function layouts(): array
    {
        return $this->layouts;
    }

    /**
     * A static segment beats a parameter at the same depth, so `/racks/new`
     * wins over `/racks/{rack}` whichever order they were registered in.
     */
    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        // Leftmost literal wins, so precedence never depends on the order the
        // autoloader happened to hand classes over.
        usort($this->pages, static fn (CompiledRoute $a, CompiledRoute $b): int => $a->beats($b));

        $this->sorted = true;
    }

    /** @return list<string> */
    private static function compile(string $template): array
    {
        $segments = CompiledRoute::split(self::normalizeTemplate($template));

        foreach ($segments as $segment) {
            if (!CompiledRoute::isParameter($segment)) {
                if (!preg_match('/^[A-Za-z0-9._~\-]+$/', $segment)) {
                    throw RoutingException::badTemplate($template, "segment '{$segment}' is not a literal path segment");
                }
                continue;
            }

            $name = trim($segment, '{}');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw RoutingException::badTemplate($template, "'{$name}' is not a valid parameter name");
            }
        }

        return $segments;
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private static function parametersOf(string $template, array $segments): array
    {
        $out = [];
        foreach ($segments as $segment) {
            if (CompiledRoute::isParameter($segment)) {
                $name = trim($segment, '{}');
                if (\in_array($name, $out, true)) {
                    throw RoutingException::badTemplate($template, "'{$name}' appears twice");
                }
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The key a layout publishes its data under, from its view folder:
     * `racks/layout` becomes `racks`, matching the spec's LayoutProps<'racks'>.
     */
    private static function layoutKeyOf(string $view): string
    {
        $slash = strrpos($view, '/');

        return $slash === false ? $view : substr($view, 0, $slash);
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = '/' . trim($template, '/');
        $template = (string) preg_replace('#/+#', '/', $template);

        return $template === '/' ? '/' : rtrim($template, '/');
    }
}
