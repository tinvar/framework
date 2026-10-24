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
 */
final class FragmentManifestProducer
{
    /** What the Vite plugin writes for a boundary whose name it could not read. */
    public const string ANY_COMPONENT = '*';

    /**
     * The manifest for every route in the registry, from the build's
     * `deferred.json`.
     *
     * Refuses a route whose view the build has no module for: the views
     * directory and the #[Page] attributes disagree, and guessing that the
     * view has no boundaries would leave its renders without a minter.
     */
    public static function produce(RouteRegistry $routes, string $deferredJson): FragmentManifest
    {
        $views = self::views($deferredJson);
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
            $any = false;
            foreach ($rendered as $view) {
                if (!\array_key_exists($view, $views)) {
                    throw new \RuntimeException(
                        "The renderer build has no module for view '{$view}' ({$page->class}). Its views are "
                        . 'the modules under the pages directory deferredBoundaries() was given.',
                    );
                }
                foreach ($views[$view] as $component) {
                    $any = $any || $component === self::ANY_COMPONENT;
                    $components[$component] = true;
                }
            }

            if ($components === []) {
                continue;
            }

            $manifest->declareRoute(self::nameOf($page), $any ? null : self::sorted(array_keys($components)));
        }

        return $manifest;
    }

    /**
     * A route that defers needs a name: the fragment endpoint finds the
     * originating route by it, and the Kernel mints for no unnamed page. An
     * unnamed one would render its boundaries without the means to load them.
     */
    private static function nameOf(CompiledRoute $page): string
    {
        return $page->name ?? throw new \RuntimeException(
            "{$page->class} renders a <Deferred> boundary but has no route name, so its fragments could never be "
            . 'found again. Name it: #[Page(..., name: \'...\')].',
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

    /** @return array<string, list<string>> view => components */
    private static function views(string $path): array
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

        $views = [];
        foreach ($decoded['views'] as $view => $components) {
            if (!\is_string($view) || !\is_array($components) || !array_is_list($components)) {
                throw new \RuntimeException("{$path} lists a view that is not a name with a list of components.");
            }
            $views[$view] = [];
            foreach ($components as $component) {
                if (!\is_string($component) || $component === '') {
                    throw new \RuntimeException("{$path} names a component that is not a string.");
                }
                $views[$view][] = $component;
            }
        }

        return $views;
    }
}
