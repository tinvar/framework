<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Http\Request;

/**
 * Which segments of a route a partial reload re-renders.
 *
 * tinvar-spec.md, "Protocol": `X-Only` / `X-Except` (dot paths) "re-run only
 * named loader fields and queries", and `X-Page` makes "partial requests
 * apply only to the same page". ADR 0015 says why the unit is a segment --
 * each layout and the page -- rather than a field: the browser holds
 * rendered elements, not loader data, so whatever a segment renders has to
 * be rendered again from data computed now, and one loader computes all of
 * its segment's fields at once.
 *
 * It selects what is rendered and sent, never what runs. Every middleware,
 * check and loader in the chain runs as it does for the whole page: a
 * loader's body can hold access logic, and a client choosing `only` must not
 * choose which of that runs (LoaderRunner).
 *
 * A path is `<segment>[.<field>...]`, where the segment is a layout's key
 * (the `'shop'` of `LayoutProps<'shop'>`) or `page`. Naming any field of a
 * segment, or the segment itself, in `X-Only` re-renders that segment.
 * `X-Except` leaves out only a segment it names whole: a field excepted from
 * a segment that is re-rendered anyway is sent with it, because sending more
 * than was asked is always correct, and less never is.
 *
 * An empty `X-Only` -- the client's `only: []` -- names no segment and is
 * still a partial reload: only the #[Always] segments are rendered (ADR
 * 0015), never every segment, as no `X-Only` at all would be.
 *
 * Two field marks reach past the segment (ADR 0045). A field path names an
 * #[Optional] field too, which is resolved only when named so -- the paths
 * are kept for the encoder. And a segment whose data has an #[Always] field
 * is re-rendered whatever the paths say, which only the loaders' results
 * can tell, so LoaderRunner adds it with including().
 *
 * Anything this cannot read -- no `X-Page`, another page's, a malformed or
 * oversized list -- is not a partial reload at all, and the page renders in
 * full. That is the safe answer to every doubt: a full page is what the
 * same request without the headers would get.
 */
final readonly class PartialReload
{
    /** The segment the page itself renders as. A layout keyed `page` would be ambiguous. */
    public const string PAGE = 'page';

    /** More paths than any page has fields is a client bug or a probe. */
    public const int MAX_PATHS = 64;

    public const int MAX_HEADER_BYTES = 4096;

    /** A segment key -- a view folder, so it may hold slashes -- then field names. */
    private const string PATH = '/^[A-Za-z0-9_\/-]+(\.[A-Za-z0-9_]+)*\z/';

    /**
     * @param list<string> $segments re-rendered, outermost first
     * @param list<string> $only     every path X-Only named, as named: which #[Optional]
     *        fields are resolved (PayloadContext::requests(), ADR 0045)
     * @param list<string> $except   every path X-Except named
     */
    private function __construct(
        public array $segments,
        public array $only = [],
        public array $except = [],
    ) {}

    /**
     * The partial reload a request asks for on this chain, or null for a
     * full render.
     */
    public static function fromRequest(Request $request, RouteChain $chain): ?self
    {
        // A document load is always the whole document.
        if (!$request->wantsRscPayload()) {
            return null;
        }

        $only = self::paths($request->header('x-only'));
        $except = self::paths($request->header('x-except'));
        if ($only === null && $except === null) {
            return null;
        }
        if ($only === false || $except === false) {
            return null;
        }

        // "Partial requests apply only to the same page." A client asking
        // for part of the page it holds, at a URL that now renders another,
        // gets that other page whole.
        if ($request->header('x-page') !== $chain->page->view) {
            return null;
        }

        $keys = [];
        foreach ($chain->layouts as $layout) {
            if ($layout->layoutKey === self::PAGE) {
                return null;
            }
            $keys[] = $layout->layoutKey;
        }
        $keys[] = self::PAGE;

        $segments = [];
        foreach ($keys as $key) {
            $named = $only === null || self::names($only, $key);
            $left = $except !== null && \in_array($key, $except, true);
            if ($named && !$left) {
                $segments[] = $key;
            }
        }

        return new self($segments, $only ?? [], $except ?? []);
    }

    /**
     * The same reload with $segment re-rendered too, in its place in the
     * chain: a segment whose data has an #[Always] field (ADR 0045).
     *
     * @param list<string> $chain every segment of the route, outermost first
     */
    public function including(string $segment, array $chain): self
    {
        if ($this->includes($segment)) {
            return $this;
        }

        $segments = array_values(array_filter(
            $chain,
            fn (string $key): bool => $key === $segment || $this->includes($key),
        ));

        return new self($segments, $this->only, $this->except);
    }

    public function includes(string $segment): bool
    {
        return \in_array($segment, $this->segments, true);
    }

    /**
     * A header's paths: null when it is absent, false when it cannot be
     * read.
     *
     * @return list<string>|false|null
     */
    private static function paths(?string $header): array|false|null
    {
        if ($header === null) {
            return null;
        }
        if (\strlen($header) > self::MAX_HEADER_BYTES) {
            return false;
        }

        $paths = array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $path): bool => $path !== '',
        ));

        if (\count($paths) > self::MAX_PATHS) {
            return false;
        }
        foreach ($paths as $path) {
            if (preg_match(self::PATH, $path) !== 1) {
                return false;
            }
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private static function names(array $paths, string $segment): bool
    {
        foreach ($paths as $path) {
            if ($path === $segment || str_starts_with($path, $segment . '.')) {
                return true;
            }
        }

        return false;
    }
}
