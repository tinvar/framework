<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;

/**
 * One #[Page] or #[Layout], compiled once.
 *
 * Templates are compiled to segments rather than a regex so a layout can be
 * tested as a prefix of a page by structure, which is how the nesting in
 * "Layout loaders" is resolved.
 */
final readonly class CompiledRoute
{
    /**
     * @param class-string                 $class
     * @param list<string>                 $segments  'racks' or '{rack}'
     * @param list<string>                 $parameters
     * @param list<class-string>           $middleware
     * @param list<Authorize>              $checks
     */
    public function __construct(
        public RouteKind $kind,
        public string $class,
        public string $template,
        public string $view,
        public ?string $name,
        public array $segments,
        public array $parameters,
        public array $middleware,
        public array $checks,
    ) {}

    /** How many segments are literal; used to rank a static route above a dynamic one. */
    public function staticSegmentCount(): int
    {
        return \count(array_filter($this->segments, static fn (string $s): bool => !self::isParameter($s)));
    }

    public function depth(): int
    {
        return \count($this->segments);
    }

    /**
     * Matches a normalized request path, returning its parameters.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        $parts = self::split($path);
        if (\count($parts) !== \count($this->segments)) {
            return null;
        }

        $parameters = [];
        foreach ($this->segments as $index => $segment) {
            $part = $parts[$index];

            if (self::isParameter($segment)) {
                if ($part === '') {
                    return null;
                }
                $parameters[trim($segment, '{}')] = rawurldecode($part);
                continue;
            }

            if ($segment !== $part) {
                return null;
            }
        }

        return $parameters;
    }

    /**
     * True when this layout wraps $page: its segments are a structural prefix.
     *
     * Compared by template rather than by matching the request path, so a
     * layout at `/sites/{site}` wraps `/sites/{site}/racks/{rack}` and not
     * `/sites/{site}x/...`.
     */
    public function wraps(self $page): bool
    {
        if ($this->depth() > $page->depth()) {
            return false;
        }

        foreach ($this->segments as $index => $segment) {
            $theirs = $page->segments[$index];

            if (self::isParameter($segment) !== self::isParameter($theirs)) {
                return false;
            }
            if (!self::isParameter($segment) && $segment !== $theirs) {
                return false;
            }
            if (self::isParameter($segment) && $segment !== $theirs) {
                return false;   // same position, different parameter name
            }
        }

        return true;
    }

    /** @param array<string, string|int> $parameters */
    public function url(array $parameters): string
    {
        $out = [];
        foreach ($this->segments as $segment) {
            if (!self::isParameter($segment)) {
                $out[] = $segment;
                continue;
            }

            $key = trim($segment, '{}');
            if (!\array_key_exists($key, $parameters)) {
                throw RoutingException::missingParameter($this->name ?? $this->template, $key);
            }
            $out[] = rawurlencode((string) $parameters[$key]);
        }

        return $out === [] ? '/' : '/' . implode('/', $out);
    }

    public static function isParameter(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    /** @return list<string> */
    public static function split(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }
}
