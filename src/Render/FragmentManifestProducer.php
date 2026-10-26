<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteRegistry;

/**
 * Writes the FragmentManifest from the build, so no application declares its
 * <Deferred> routes by hand.
 *
 * Two halves, neither able to answer alone. The renderer build knows which
 * VIEWS reach a boundary: its `deferredBoundaries()` Vite plugin follows each
 * page module's imports through the rsc graph and writes `deferred.json`, a
 * view id to the components reachable from it. The route registry knows
 * which views each ROUTE renders: its page, and every layout wrapping it. A
 * route defers when any of those views does, with the components of all of
 * them.
 *
 * A hand-kept list drifts. One that left a route out had that route's
 * boundaries fail to render, which is found; one that kept a route after its
 * last boundary went handed every render of it the signing key, which is not
 * -- the hole ADR 0009 closes by giving a minter only to routes that need it.
 *
 * Each route's query allowlist is joined the same way. The build writes the
 * query names each view's graph can call, and those each deferred component
 * the rsc entry registers can call; a route may call what its page and every
 * layout around it can, and what every component its renders may name can,
 * since that component's fragment renders under the route's allowlist. A
 * hand-kept allowlist drifts as the boundaries did: one that kept a query
 * the page stopped calling let a compromised dependency call it on every
 * render of the route -- tinvar-spec.md, "PHP rejects any other name on that
 * route's socket, so a compromised dependency can't call queries the page
 * never uses".
 */
final class FragmentManifestProducer
{
    /** What the Vite plugin writes for a boundary whose name it could not read. */
    public const string ANY_COMPONENT = '*';

    /** What a page does that needs a route name, for nameOf()'s refusal. */
    private const string DEFERS = 'renders a <Deferred> boundary';

    private const string QUERIES = 'calls a query';

    /**
     * The manifest for every route in the registry, from the build's
     * `deferred.json`.
     *
     * Refuses a route whose view the build has no module for: the views
     * directory and the #[Page] attributes disagree, and guessing that the
     * view has no boundaries would leave its renders without a minter. And a
     * route whose boundaries name a component the build found no registry
     * entry for, or an unnamed page that calls a query: either way its
     * renders would be refused what they call, at runtime rather than here.
     */
    public static function produce(RouteRegistry $routes, string $deferredJson): FragmentManifest
    {
        ['views' => $views, 'queries' => $viewQueries, 'components' => $componentQueries] = self::read($deferredJson);
        $manifest = new FragmentManifest();
        $layouts = $routes->layouts();

        foreach ($routes->pages() as $page) {
            $rendered = [$page->view];
            foreach ($layouts as $layout) {
                if ($layout->wraps($page)) {
                    $rendered[] = $layout->view;
                }
            }

            $components = [];
            $queries = [];
            $any = false;
            foreach ($rendered as $view) {
                if (!\array_key_exists($view, $views)) {
                    throw new \RuntimeException(
                        "The renderer build has no module for view '{$view}' ({$page->class}). Its views are "
                        . 'the modules under the pages directory deferredBoundaries() was given.',
                    );
                }
                if (!\array_key_exists($view, $viewQueries)) {
                    throw new \RuntimeException("{$deferredJson} lists no queries for view '{$view}' ({$page->class}).");
                }
                foreach ($views[$view] as $component) {
                    $any = $any || $component === self::ANY_COMPONENT;
                    $components[$component] = true;
                }
                foreach ($viewQueries[$view] as $query) {
                    $queries[$query] = true;
                }
            }

            // A fragment of any component the route may mint for renders
            // under this route's allowlist: every registered one, where the
            // build could not read which.
            $deferred = $any ? array_keys($componentQueries) : array_keys($components);
            foreach ($deferred as $component) {
                $component = (string) $component;
                if (!\array_key_exists($component, $componentQueries)) {
                    throw new \RuntimeException(
                        "{$page->class} renders <Deferred component=\"{$component}\">, and the renderer build found no "
                        . "'{$component}' in the rsc entry's deferredRegistry(), so the queries its fragment calls are unknown. "
                        . 'Register it there.',
                    );
                }
                foreach ($componentQueries[$component] as $query) {
                    $queries[$query] = true;
                }
            }

            if ($queries !== []) {
                $manifest->declareQueries(self::nameOf($page, self::QUERIES), self::sorted(array_map('strval', array_keys($queries))));
            } elseif ($page->name !== null) {
                // Listed with none, so the file says so rather than leaving
                // the route out.
                $manifest->declareQueries($page->name, []);
            }

            if ($components === []) {
                continue;
            }

            $manifest->declareRoute(self::nameOf($page, self::DEFERS), $any ? null : self::sorted(array_keys($components)));
        }

        return $manifest;
    }

    /**
     * A route that defers needs a name: the fragment endpoint finds the
     * originating route by it, and the Kernel mints for no unnamed page. An
     * unnamed one would render its boundaries without the means to load them.
     * So does a route that calls a query: its allowlist is found by the name,
     * and an unnamed page's renders are allowed nothing.
     *
     * @param string $what what the page does that needs one
     */
    private static function nameOf(CompiledRoute $page, string $what): string
    {
        return $page->name ?? throw new \RuntimeException(
            "{$page->class} {$what} but has no route name, so "
            . ($what === self::DEFERS ? 'its fragments could never be found again' : 'its query allowlist could never be found')
            . '. Name it: #[Page(..., name: \'...\')].',
        );
    }

    /**
     * @param list<string> $components
     * @return list<string>
     */
    private static function sorted(array $components): array
    {
        sort($components, SORT_STRING);

        return $components;
    }

    /**
     * What the Vite plugin wrote: each view's <Deferred> components and the
     * queries it can call, and the queries each registered deferred
     * component can call. A file from before the build listed queries is
     * refused: read as "no queries", every route's renders would be refused
     * what they call, and a stale build is the likelier cause.
     *
     * @return array{views: array<string, list<string>>, queries: array<string, list<string>>, components: array<string, list<string>>}
     */
    private static function read(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException(
                "No {$path}: build the app with the deferredBoundaries() Vite plugin in its rsc environment.",
            );
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$path} is not valid JSON.", previous: $e);
        }

        if (!\is_array($decoded) || ($decoded['format'] ?? null) !== 1 || !\is_array($decoded['views'] ?? null)) {
            throw new \RuntimeException("{$path} is not a deferred-boundary list this version of Tinvar reads.");
        }
        if (!\is_array($decoded['queries'] ?? null) || !\is_array($decoded['componentQueries'] ?? null)) {
            throw new \RuntimeException(
                "{$path} lists no queries: build the app again, with this version's deferredBoundaries() Vite plugin.",
            );
        }

        return [
            'views' => self::names($decoded['views'], $path, 'view', 'component'),
            'queries' => self::names($decoded['queries'], $path, 'view', 'query'),
            'components' => self::names($decoded['componentQueries'], $path, 'deferred component', 'query'),
        ];
    }

    /**
     * A map of names to lists of names, read strictly: this decides what a
     * route's renders may call.
     *
     * @param array<array-key, mixed> $map
     * @return array<string, list<string>>
     */
    private static function names(array $map, string $path, string $key, string $value): array
    {
        $names = [];
        foreach ($map as $name => $list) {
            if (!\is_string($name) || $name === '' || !\is_array($list) || !array_is_list($list)) {
                throw new \RuntimeException("{$path} lists a {$key} that is not a name with a list.");
            }
            $names[$name] = [];
            foreach ($list as $item) {
                if (!\is_string($item) || $item === '') {
                    throw new \RuntimeException("{$path} names a {$value} that is not a string.");
                }
                $names[$name][] = $item;
            }
        }

        return $names;
    }
}
