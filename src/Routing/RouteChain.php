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
     * @return list<class-string>
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

    /**
     * The key a layout's data is published under.
     *
     * Derived once at compile time, where duplicates are rejected: two layouts
     * sharing a key would otherwise overwrite each other here, and the outer
     * one's data would vanish with no error.
     */
    public function layoutKey(CompiledRoute $layout): string
    {
        return $layout->layoutKey;
    }
}
