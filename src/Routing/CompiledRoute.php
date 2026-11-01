<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Http\Request;

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
     * @param list<string>                 $middleware
     * @param list<Authorize>              $checks
     * @param bool|null                    $encryptHistory a page's #[EncryptHistory], or null
     *                                                    for the default (ADR 0019)
     * @param class-string|null $tenantFrom the source its
     *                                                    #[TenantFrom] names, or null for none
     * @param list<\Tinvar\Attributes\Panel> $panels  every #[Panel] it declares
     * @param HostPattern|null $host the host its #[Domain] holds it to, or null for the
     *                               primary host (ADR 0149); its parameters lead $parameters
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
        public string $layoutKey = '',
        public ?bool $encryptHistory = null,
        public ?string $tenantFrom = null,
        public array $panels = [],
        public ?HostPattern $host = null,
    ) {}

    /** The #[Domain] pattern it is served on, as written; null for the primary host. */
    public function domain(): ?string
    {
        return $this->host?->pattern;
    }

    /** Whether it is served on the same hosts as $other: the same pattern shape, or both on the primary host. */
    public function sameHostAs(self $other): bool
    {
        return $this->host?->shape() === $other->host?->shape();
    }

    public function depth(): int
    {
        return \count($this->segments);
    }

    /**
     * The template with every parameter's name dropped: the paths a route
     * matches depend on where its parameters sit, never on what they are
     * called.
     */
    public function shape(): string
    {
        return ($this->host === null ? '' : $this->host->shape()) . '/' . implode('/', array_map(
            static fn (string $segment): string => self::isParameter($segment) ? '{}' : $segment,
            $this->segments,
        ));
    }

    /**
     * Matches a normalized request path, returning its parameters.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        return $this->matchParts(self::split($path));
    }

    /**
     * Matches a request path already split into its segments (split()):
     * what a registry matching one path against every page splits once.
     *
     * @param list<string> $parts
     * @return array<string, string>|null
     */
    public function matchParts(array $parts): ?array
    {
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
     * Parameters match POSITIONALLY, not by name. Comparing names here fails
     * open: a page at `/sites/{siteId}/leak` would escape the `/sites/{site}`
     * layout, the layout would be absent from the chain, and its #[Authorize]
     * would never run -- the exact Next.js pitfall the spec calls out, caused
     * by a one-character typo.
     *
     * A name mismatch is still a bug, but it is caught loudly at registration
     * (by the route registry) rather than silently
     * unguarding a route here.
     */
    public function wraps(self $page): bool
    {
        // A layout wraps only the pages on its own host: the same path is
        // another page on another host, under that host's layouts.
        if ($this->depth() > $page->depth() || !$this->sameHostAs($page)) {
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
        }

        return true;
    }

    /**
     * The first index at which this route's parameter name differs from
     * $page's, or null when they agree over the shared prefix.
     */
    public function parameterNameMismatch(self $page): ?int
    {
        foreach ($this->segments as $index => $segment) {
            if (self::isParameter($segment) && $segment !== $page->segments[$index]) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Ranks this route against another for the same path.
     *
     * Leftmost literal wins, so `/sites/new/{x}` beats `/sites/{s}/edit`
     * whatever order they registered in. Without this, precedence would depend
     * on autoloader order, which is not something an application controls.
     */
    public function beats(self $other): int
    {
        $shared = min($this->depth(), $other->depth());

        for ($index = 0; $index < $shared; $index++) {
            $mine = self::isParameter($this->segments[$index]);
            $theirs = self::isParameter($other->segments[$index]);

            if ($mine !== $theirs) {
                return $mine ? 1 : -1;   // a literal sorts first
            }
        }

        return $other->depth() <=> $this->depth();
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

            $value = (string) $parameters[$key];

            // A parameter is one segment. Encoding a separator, a traversal
            // step, a backslash or a control character would build a URL that
            // this same router then refuses to match -- Request decodes each
            // segment and holds it to the same rule -- so it is refused here
            // instead of generating a link that cannot be followed.
            if ($value === '' || !Request::isPathSegment($value)) {
                throw RoutingException::badParameterValue($this->name ?? $this->template, $key, $value);
            }

            $out[] = rawurlencode($value);
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
