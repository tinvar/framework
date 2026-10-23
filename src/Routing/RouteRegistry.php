<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Page;

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

    /** @var array<string, RouteChain> */
    private array $chainCache = [];

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

        $route = new CompiledRoute(
            kind: $kind,
            class: $class,
            template: self::normalizeTemplate($declaration->path),
            view: $declaration->view,
            name: $name,
            segments: self::compile($declaration->path),
            parameters: self::parametersOf($declaration->path),
            middleware: self::middlewareOf($reflection),
            checks: array_map(
                static fn (\ReflectionAttribute $a): Authorize => $a->newInstance(),
                $reflection->getAttributes(Authorize::class),
            ),
        );

        $this->add($route);

        return $route;
    }

    /** @param list<class-string> $classes @return list<CompiledRoute> */
    public function registerAll(array $classes): array
    {
        return array_map($this->register(...), $classes);
    }

    private function add(CompiledRoute $route): void
    {
        $existing = $route->kind === RouteKind::Page ? $this->pages : $this->layouts;
        foreach ($existing as $other) {
            if ($other->template === $route->template) {
                throw RoutingException::duplicate($route->template, $other->class, $route->class);
            }
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
        $this->chainCache = [];
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

            return $this->chainCache[$page->template] ??= new RouteChain(
                page: $page,
                layouts: $this->layoutsFor($page),
                parameters: $parameters,
            );
        }

        return null;
    }

    /** @return list<CompiledRoute> outermost first */
    private function layoutsFor(CompiledRoute $page): array
    {
        $wrapping = array_values(array_filter(
            $this->layouts,
            static fn (CompiledRoute $layout): bool => $layout->wraps($page),
        ));

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

        usort($this->pages, static function (CompiledRoute $a, CompiledRoute $b): int {
            return [$b->staticSegmentCount(), $b->depth()] <=> [$a->staticSegmentCount(), $a->depth()];
        });

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

    /** @return list<string> */
    private static function parametersOf(string $template): array
    {
        $out = [];
        foreach (self::compile($template) as $segment) {
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
     * @param \ReflectionClass<object> $reflection
     * @return list<class-string>
     */
    private static function middlewareOf(\ReflectionClass $reflection): array
    {
        $out = [];
        foreach ($reflection->getAttributes(Middleware::class) as $attribute) {
            foreach ($attribute->newInstance()->classes as $class) {
                if (!\in_array($class, $out, true)) {
                    $out[] = $class;
                }
            }
        }

        return $out;
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = '/' . trim($template, '/');
        $template = (string) preg_replace('#/+#', '/', $template);

        return $template === '/' ? '/' : rtrim($template, '/');
    }
}
