<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Attributes\Panel;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteRegistry;

/**
 * Holds the client build's panels to the routes' own.
 *
 * tinvar-spec.md, "Panels and scope": a panel narrows what a request sees.
 * Its client components are shipped to whoever loads a page that reaches
 * them, so the build keeps each panel's to chunks of its own: panelChunks()
 * (`@tinvar/renderer/vite`) puts a client reference that only the panel's
 * views reach in the panel's chunk, checks that no chunk a page outside the
 * panel loads holds its code, and says in `panels.json` which views it took
 * to be each panel's -- by the directory the Vite config names.
 *
 * Which panel a route is in is PHP's: its #[Panel], or its namespace
 * (PanelRegistry). This joins the two, as FragmentManifestProducer joins the
 * build's boundaries with the routes, and refuses a build where they
 * disagree -- an admin page whose view sits outside the admin directory
 * would have its client components shipped to customers, and nothing else
 * would notice:
 *
 * - every panel a route is in must be one the build kept to itself;
 * - a page's view must be in its route's panel's directory, and in no
 *   panel's when its route is in none;
 * - a layout's view in a panel's directory may wrap only that panel's pages.
 */
final class PanelBundles
{
    /**
     * @throws \RuntimeException naming every disagreement, or a build with no report
     */
    public static function check(RouteRegistry $routes, PanelRegistry $registry, string $panelsJson): void
    {
        $inUse = [];
        foreach ($routes->pages() as $page) {
            $panel = self::panelOf($page, $routes, $registry);
            if ($panel !== null) {
                $inUse[$panel] = true;
            }
        }

        if (!is_file($panelsJson)) {
            if ($inUse === []) {
                return;
            }

            throw new \RuntimeException(
                "No {$panelsJson}, and routes are in the " . implode(', ', array_keys($inUse)) . ' panel(s): build the app '
                . 'with panelChunks() (@tinvar/renderer/vite) beside the RSC plugin, so each panel\'s client code stays '
                . 'out of every other page\'s download.',
            );
        }

        $views = self::views($panelsJson);
        $panelOfView = [];
        foreach ($views as $panel => $list) {
            if (!$registry->has($panel)) {
                throw new \RuntimeException("{$panelsJson} keeps client code for a panel '{$panel}' no PanelDefinition defines.");
            }
            foreach ($list as $view) {
                $panelOfView[$view] = $panel;
            }
        }

        $violations = [];
        foreach (array_keys($inUse) as $panel) {
            if (!\array_key_exists($panel, $views)) {
                $violations[] = "routes are in the {$panel} panel, and the build keeps no chunk of its own for it: name its views' directory in panelChunks()";
            }
        }

        $layouts = $routes->layouts();
        foreach ($routes->pages() as $page) {
            $panel = self::panelOf($page, $routes, $registry);
            $built = $panelOfView[$page->view] ?? null;
            if ($built !== $panel) {
                $violations[] = \sprintf(
                    "%s is in %s, and its view '%s' is in %s",
                    $page->class,
                    $panel === null ? 'no panel' : "the {$panel} panel",
                    $page->view,
                    $built === null ? 'no panel\'s directory' : "the {$built} panel's directory",
                );
            }

            foreach ($layouts as $layout) {
                $wrapping = $panelOfView[$layout->view] ?? null;
                if ($wrapping !== null && $wrapping !== $panel && $layout->wraps($page)) {
                    $violations[] = "{$layout->class}'s view '{$layout->view}' is in the {$wrapping} panel's directory, and wraps {$page->class}, which is not in that panel";
                }
            }
        }

        if ($violations !== []) {
            throw new \RuntimeException("The client build's panels and the routes' disagree:\n  " . implode("\n  ", array_values(array_unique($violations))));
        }
    }

    /** A page's panel: declared on it or a layout around it, or else its namespace's. */
    private static function panelOf(CompiledRoute $page, RouteRegistry $routes, PanelRegistry $registry): ?string
    {
        /** @var list<Panel> $declared */
        $declared = [];
        foreach ($routes->layouts() as $layout) {
            if ($layout->wraps($page)) {
                $declared = [...$declared, ...$layout->panels];
            }
        }
        $declared = [...$declared, ...$page->panels];

        return $declared === [] ? $registry->panelOf($page->class) : $declared[0]->name;
    }

    /** @return array<string, list<string>> panel => its views */
    private static function views(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$path} is not valid JSON.", previous: $e);
        }

        if (!\is_array($decoded) || ($decoded['format'] ?? null) !== 1 || !\is_array($decoded['panels'] ?? null)) {
            throw new \RuntimeException("{$path} is not a panel report this version of Tinvar reads.");
        }

        $views = [];
        foreach ($decoded['panels'] as $panel => $entry) {
            if (!\is_string($panel) || !\is_array($entry) || !\is_array($entry['views'] ?? null) || !array_is_list($entry['views'])) {
                throw new \RuntimeException("{$path} lists a panel that is not a name with a list of views.");
            }
            $views[$panel] = [];
            foreach ($entry['views'] as $view) {
                if (!\is_string($view) || $view === '') {
                    throw new \RuntimeException("{$path} names a view that is not a string.");
                }
                $views[$panel][] = $view;
            }
        }

        return $views;
    }
}
