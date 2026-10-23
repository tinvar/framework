<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Page;
use Tinvar\Auth\BuildRules;
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

    /** @param class-string $class */
    public function register(string $class): CompiledRoute
    {
        $reflection = new \ReflectionClass($class);

        $pageAttribute = $reflection->getAttributes(Page::class)[0] ?? null;
        $layoutAttribute = $reflection->getAttributes(Layout::class)[0] ?? null;

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

        foreach ($this->layouts as $layout) {
            if (!$layout->wraps($page)) {
                continue;
            }

            // Checked here rather than at registration because it depends on
            // both routes existing, and they may register in either order.
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

            $wrapping[] = $layout;
        }

        usort($wrapping, static fn (CompiledRoute $a, CompiledRoute $b): int => $a->depth() <=> $b->depth());

        return $wrapping;
    }

    public function named(string $name): CompiledRoute
    {
        return $this->byName[$name] ?? throw RoutingException::unknownName($name);
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
