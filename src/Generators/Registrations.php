<?php

declare(strict_types=1);

namespace Tinvar\Generators;

use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;

/**
 * Where a generated class is registered once it is written: the lists an
 * application keeps of its pages, queries, actions and models -- a
 * Kernel's ROUTES, QUERIES, ACTIONS and MODELS, a console's APP_MODELS --
 * and, for a page, the renderer's route table.
 *
 *     new Registrations(
 *         lists: [
 *             'page' => [['file' => 'app/Kernel.php', 'constant' => 'ROUTES']],
 *             'model' => [['file' => 'app/Kernel.php', 'constant' => 'MODELS']],
 *         ],
 *         routeTable: 'resources/entries/rsc.tsx',
 *         routes: Kernel::ROUTES,
 *     )
 *
 * Each edit is ClassList's or RouteTable's: made where the file reads as
 * the list or table it is meant to be, never twice, and refused otherwise.
 * A refusal registers nothing anywhere else's expense: each file is its
 * own edit, and the developer is told, file by file, what was registered
 * and what is left to do by hand and why. Nothing is written outside the
 * application the layout names.
 *
 * A page's layouts in the route table are the ones PHP will run its
 * loaders in: the application's layouts that wrap the page's path, read
 * from its routes with the new page among them, outermost first.
 */
final readonly class Registrations
{
    public const array KINDS = ['page', 'query', 'action', 'model'];

    /**
     * @param array<string, list<array{file: string, constant: string}>> $lists by kind:
     *        page, query, action or model
     * @param string|null $routeTable the renderer's rsc entry, holding const ROUTES
     * @param list<class-string> $routes the application's pages and layouts, for a
     *        new page's layouts
     */
    public function __construct(
        private array $lists = [],
        private ?string $routeTable = null,
        private array $routes = [],
    ) {
        foreach (array_keys($lists) as $kind) {
            if (!\in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("'{$kind}' is not a kind of class a generator writes: " . implode(', ', self::KINDS) . '.');
            }
        }
    }

    /** Whether anything is registered where: none, as a generator ran before this. */
    public function none(): bool
    {
        return $this->lists === [] && $this->routeTable === null;
    }

    /**
     * Registers $class, a $kind just written, and says what was done.
     *
     * @param string|null $view a page's view, for the route table
     * @return array{list<string>, list<string>} lines saying what was registered, and
     *         what was left to do by hand
     */
    public function register(string $kind, string $class, AppLayout $layout, ?string $view = null): array
    {
        $done = [];
        $left = [];
        foreach ($this->lists[$kind] ?? [] as ['file' => $file, 'constant' => $constant]) {
            $shown = $file;
            try {
                $file = $layout->inProject($file);
                $shown = $layout->relative($file);
                $done[] = ClassList::add($file, $constant, $class)
                    ? "registered {$class} in {$shown} ({$constant})"
                    : "{$class} is in {$shown} ({$constant}) already";
            } catch (RegistrationRefused|GenerationRefused $e) {
                $left[] = "Left {$shown} as it was -- {$e->getMessage()}: add {$class} to {$constant} there by hand.";
            }
        }

        if ($kind === 'page' && $view !== null && $this->routeTable !== null) {
            $shown = $this->routeTable;
            try {
                $table = $layout->inProject($this->routeTable);
                $shown = $layout->relative($table);
                $layouts = $this->layoutsOf($class);
                $component = RouteTable::importPath($table, substr($layout->viewPath($view), 0, -\strlen('.tsx')));
                $done[] = RouteTable::add($table, $view, $component, $layouts)
                    ? "registered '{$view}' in {$shown}" . ($layouts === [] ? ', in no layout' : ', in ' . implode(', ', $layouts))
                    : "'{$view}' is in {$shown} already";
            } catch (RegistrationRefused|GenerationRefused $e) {
                $left[] = "Left {$shown} as it was -- {$e->getMessage()}: add the view '{$view}' to its ROUTES by hand.";
            }
        }

        return [$done, $left];
    }

    /**
     * The layout keys PHP will render $page in, outermost first.
     *
     * @return list<string>
     * @throws RegistrationRefused where the page cannot be read among the routes
     */
    private function layoutsOf(string $page): array
    {
        if (!class_exists($page)) {
            throw new RegistrationRefused("{$page} does not load yet, so which layouts wrap it is not known");
        }
        $registry = new RouteRegistry();
        try {
            $registry->registerAll(array_values(array_unique([...$this->routes, $page])));
        } catch (RoutingException $e) {
            throw new RegistrationRefused("the routes with {$page} among them do not register ({$e->getMessage()})");
        }

        $compiled = null;
        foreach ($registry->pages() as $route) {
            if ($route->class === $page) {
                $compiled = $route;
            }
        }
        if ($compiled === null) {
            throw new RegistrationRefused("{$page} is not a page the routes register");
        }

        $wrapping = array_values(array_filter($registry->layouts(), static fn (CompiledRoute $layout): bool => $layout->wraps($compiled)));
        usort($wrapping, static fn (CompiledRoute $a, CompiledRoute $b): int => $a->depth() <=> $b->depth());

        return array_map(static fn (CompiledRoute $layout): string => $layout->layoutKey, $wrapping);
    }
}
