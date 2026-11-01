<?php

declare(strict_types=1);

namespace Tinvar\Routing;

/**
 * A matched page with the layouts that wrap it, outermost first, plus the
 * parameters taken from the path.
 *
 * "Loaders run outermost first" and "Authorization always runs ... PHP runs
 * #[Authorize] for every layout in the route chain" -- both operate on this.
 */
final readonly class RouteChain
{
    /**
     * @param list<CompiledRoute>   $layouts outermost first
     * @param array<string, string> $parameters
     */
    public function __construct(
        public CompiledRoute $page,
        public array $layouts,
        public array $parameters,
    ) {}

    /**
     * Every route in the chain, outermost layout to page.
     *
     * @return list<CompiledRoute>
     */
    public function all(): array
    {
        return [...$this->layouts, $this->page];
    }

    /**
     * Middleware for the whole chain, outer to inner, deduplicated so a class
     * listed on both a layout and its page runs once.
     *
     * @return list<string> each a middleware class, or a middleware attribute's reference (DeclaredMiddleware)
     */
    public function middleware(): array
    {
        $out = [];
        foreach ($this->all() as $route) {
            foreach ($route->middleware as $class) {
                if (!\in_array($class, $out, true)) {
                    $out[] = $class;
                }
            }
        }

        return $out;
    }

    /**
     * Where the chain's tenant comes from: the #[TenantFrom] of its page or
     * any layout around it, or null when none declares one.
     *
     * A route group's declaration covers the pages in it, and a page may
     * declare one under a group that does not. Two that disagree never
     * reach here -- RouteRegistry refuses the pair when the second of them
     * registers -- so the innermost is the chain's.
     *
     * @return class-string|null
     */
    public function tenantSource(): ?string
    {
        foreach (array_reverse($this->all()) as $route) {
            if ($route->tenantFrom !== null) {
                return $route->tenantFrom;
            }
        }

        return null;
    }

    /**
     * Every #[Panel] in the chain, outermost layout to page.
     *
     * All of them, since each narrows: a route group in the admin panel
     * requiring one permission and a page in it requiring another needs
     * both. Two naming different panels never reach here -- RouteRegistry
     * refuses the pair -- so they name one.
     *
     * @return list<\Tinvar\Attributes\Panel>
     */
    public function panels(): array
    {
        $out = [];
        foreach ($this->all() as $route) {
            $out = [...$out, ...$route->panels];
        }

        return $out;
    }

    /**
     * Every declared check in the chain, paired with the route that declared it.
     *
     * @return list<array{route: CompiledRoute, check: \Tinvar\Attributes\Authorize}>
     */
    public function checks(): array
    {
        $out = [];
        foreach ($this->all() as $route) {
            foreach ($route->checks as $check) {
                $out[] = ['route' => $route, 'check' => $check];
            }
        }

        return $out;
    }
}
