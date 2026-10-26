<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * Which deferred boundaries each statically-built page contains.
 *
 * tinvar-spec.md: "Fragments inside static pages get their tokens from a
 * small per-visitor bootstrap request, never from the build."
 *
 * The reason for "never from the build" is that a token minted at build time
 * has no session to bind to, so one token would be every visitor's — and the
 * session binding is what makes a stolen token inert. A per-visitor request
 * fixes that, but it raises the question this class exists to answer: when
 * the client asks for its tokens, who says which boundaries it may have?
 *
 * Not the client. A bootstrap that signed whatever component and props the
 * page source claimed would hand the signing key to anyone who can edit a
 * copy of the HTML — worse than the renderer's mint, because the renderer is
 * at least code this server started. So the build records the boundaries it
 * emitted, per route, and the bootstrap mints exactly those. The client says
 * only WHICH PAGE it is on; the server already knows what that page contains.
 *
 * This is the same shape as the route and query allowlists elsewhere in the
 * framework: the client names something, and the server looks it up rather
 * than trusting the description that came with it.
 *
 * It also says which ROUTES contain a boundary at all, rendered statically or
 * not. ADR 0009: "A page with no deferred boundaries cannot be talked into
 * minting" -- which holds only if a render is handed a minter when its route
 * has a boundary to mint for, and otherwise none, so a mint frame from it is
 * a protocol violation. A dynamically rendered page's props are known only as
 * it renders, so for those the manifest records the route, and the components
 * its boundaries name when the build could read them. FragmentManifestProducer
 * writes both from the renderer build; nothing is declared by hand.
 *
 * And the query names each route's renders may call: tinvar-spec.md, "At
 * build, the server-component graph of each route gives the list of query
 * names it may call. PHP rejects any other name on that route's socket."
 * Here because it is made the same way -- the renderer build's graph, per
 * view, joined with the routes -- from the same file of the same build, and
 * read where the Kernel already reads this, once, as it boots: a route's
 * boundaries and its allowlist can never come from two builds. A route this
 * does not list may call nothing; see queriesFor().
 */
final class FragmentManifest
{
    /**
     * Keyed by route AND the params the page was built with.
     *
     * A statically-built page is a concrete URL, not a template. Keying by
     * route alone let a caller pair ANY params with the fixed props the build
     * recorded -- so a page built for one entity could be bootstrapped with
     * another's params, and the resulting token carried props belonging to
     * neither. The props are not authorized by the fragment endpoint (it
     * authorizes the route chain against the params), so the pairing has to
     * be the one the build actually emitted.
     *
     * @var array<string, list<array{id: string, component: string, props: array<string, mixed>}>>
     */
    private array $byPage = [];

    /** @var array<string, true> routes whose renders contain a boundary, by name */
    private array $deferring = [];

    /**
     * The components each route's renders may name: a list when the build
     * read every boundary's, null when it could not. A route with no entry
     * may name any. See componentsFor().
     *
     * @var array<string, list<string>|null>
     */
    private array $components = [];

    /**
     * The query names each route's renders may call, by route name. A route
     * with no entry may call none.
     *
     * @var array<string, list<string>>
     */
    private array $queries = [];

    /**
     * The params of each built page, by the same key as $byPage, so the
     * manifest can be written back out.
     *
     * @var array<string, array<string, string>>
     */
    private array $pageParams = [];

    /** The shape toArray() writes, so a later one can be told apart. */
    public const int FORMAT = 1;

    /** The file this was read from, when it was: what refreshed() reads again. */
    private ?string $path = null;

    /** The bytes the latest manifest refreshed() returns was parsed from. */
    private ?string $bytes = null;

    /** What refreshed() last read, while the file still holds it. */
    private ?self $latest = null;

    /**
     * @param array<string, list<array{
     *     id: string,
     *     component: string,
     *     props?: array<string, mixed>,
     *     params?: array<string, string|int>
     * }>> $boundaries route name => the boundaries that route's built pages contain
     * @param list<string> $routes routes whose pages contain a <Deferred>
     *        boundary however they are rendered -- what a dynamic render
     *        needs, before it may mint
     */
    public function __construct(array $boundaries = [], array $routes = [])
    {
        foreach ($routes as $route) {
            $this->declareRoute($route);
        }

        foreach ($boundaries as $route => $list) {
            foreach ($list as $boundary) {
                $this->declare(
                    (string) $route,
                    $boundary['id'],
                    $boundary['component'],
                    $boundary['props'] ?? [],
                    $boundary['params'] ?? [],
                );
            }
        }
    }

    /**
     * Reads a manifest the build wrote: what toArray() produces -- `tinvar
     * build:fragments` writes one -- or, from before the build wrote any,
     * the bare map of route to built boundaries the constructor takes.
     *
     * A missing file is an empty manifest rather than an error: an
     * application with no static pages has nothing to declare, and failing to
     * boot over it would be the wrong trade. A file that exists and is
     * malformed IS an error, because that is a broken build rather than an
     * absent one.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            $manifest = new self();
            $manifest->path = $path;

            return $manifest;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read the fragment manifest at {$path}.");
        }

        $manifest = self::parse($raw, $path);
        $manifest->path = $path;
        $manifest->bytes = $raw;

        return $manifest;
    }

    /**
     * The manifest as its file holds it now, for what a static page's
     * boundaries are -- which `tinvar static:revalidate` rewrites while the
     * workers run (StaticBuilder::rebuild()).
     *
     * Read on every call and parsed only when the bytes differ from those
     * last parsed, as FileBuildStore reads the build history. Only the
     * built pages are asked of it: which routes may mint, and for which
     * components, is the build's and stays as the worker booted with it,
     * as does what each route may query. A manifest made in memory is its
     * own latest.
     */
    public function refreshed(): self
    {
        if ($this->path === null) {
            return $this;
        }

        // Null for no file, as when there was none to read at boot.
        $raw = null;
        if (is_file($this->path)) {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                throw new \RuntimeException("Cannot read the fragment manifest at {$this->path}.");
            }
        }

        if ($raw === $this->bytes) {
            return $this->latest ?? $this;
        }

        $latest = $raw === null ? new self() : self::parse($raw, $this->path);
        $this->bytes = $raw;

        return $this->latest = $latest;
    }

    private static function parse(string $raw, string $path): self
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("The fragment manifest at {$path} is not valid JSON.", previous: $e);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException("The fragment manifest at {$path} is not an object.");
        }

        // The bare map has a list for every key; `format` as a number is
        // the written form's, and no route's boundaries.
        if (!\array_key_exists('format', $decoded) || \is_array($decoded['format'])) {
            /** @var array<string, list<array{id: string, component: string, props?: array<string, mixed>}>> $decoded */
            return new self($decoded);
        }

        return self::fromArray($decoded, $path);
    }

    /**
     * What toArray() wrote, checked as it is read: this decides which
     * renders may use the signing key, so a field read leniently -- a
     * component list that is really an object -- is a check that silently
     * does not apply.
     *
     * @param array<array-key, mixed> $data
     */
    private static function fromArray(array $data, string $path): self
    {
        $routes = $data['routes'] ?? [];
        $pages = $data['pages'] ?? [];
        // Absent from a manifest written before the build listed them: every
        // route may then call nothing, which is the side to fail on.
        $queries = $data['queries'] ?? [];

        if ($data['format'] !== self::FORMAT || !\is_array($routes) || !\is_array($pages) || !\is_array($queries)) {
            throw new \RuntimeException("The fragment manifest at {$path} is not one this version of Tinvar reads.");
        }

        $manifest = new self();
        foreach ($routes as $route => $components) {
            if (!\is_string($route) || ($components !== null && (!\is_array($components) || !array_is_list($components)))) {
                throw new \RuntimeException("The fragment manifest at {$path} lists a route that is not a name with a list or null.");
            }
            $names = null;
            if ($components !== null) {
                $names = [];
                foreach ($components as $component) {
                    if (!\is_string($component)) {
                        throw new \RuntimeException("The fragment manifest at {$path} names a component that is not a string.");
                    }
                    $names[] = $component;
                }
            }
            $manifest->declareRoute($route, $names);
        }

        foreach ($queries as $route => $names) {
            if (!\is_string($route) || $route === '' || !\is_array($names) || !array_is_list($names)) {
                throw new \RuntimeException("The fragment manifest at {$path} lists a route's queries that are not a name with a list.");
            }
            $allowed = [];
            foreach ($names as $name) {
                if (!\is_string($name) || $name === '') {
                    throw new \RuntimeException("The fragment manifest at {$path} allows a query that is not a name.");
                }
                $allowed[] = $name;
            }
            $manifest->declareQueries($route, $allowed);
        }

        foreach ($pages as $route => $boundaries) {
            if (!\is_string($route) || !\is_array($boundaries) || !array_is_list($boundaries)) {
                throw new \RuntimeException("The fragment manifest at {$path} lists a page that is not a route with a list of boundaries.");
            }
            foreach ($boundaries as $boundary) {
                if (!\is_array($boundary) || !\is_string($boundary['id'] ?? null) || !\is_string($boundary['component'] ?? null)
                    || !\is_array($boundary['props'] ?? []) || !\is_array($boundary['params'] ?? [])) {
                    throw new \RuntimeException("The fragment manifest at {$path} has a boundary without a string id and component.");
                }
                /** @var array<string, mixed> $props */
                $props = $boundary['props'] ?? [];
                /** @var array<string, string|int> $params */
                $params = $boundary['params'] ?? [];
                $manifest->declare($route, $boundary['id'], $boundary['component'], $props, $params);
            }
        }

        return $manifest;
    }

    /**
     * The manifest as fromFile() reads it: every route that defers, with the
     * components its renders may name (null for any), every built page's
     * boundaries with the params it was built for, and each route's query
     * allowlist.
     *
     * @return array{
     *     format: int,
     *     routes: array<string, list<string>|null>,
     *     pages: array<string, list<array{id: string, component: string, props: array<string, mixed>, params: array<string, string>}>>,
     *     queries: array<string, list<string>>
     * }
     */
    public function toArray(): array
    {
        $queries = $this->queries;
        ksort($queries);

        $routes = [];
        foreach (array_keys($this->deferring) as $route) {
            $routes[$route] = $this->componentsFor($route);
        }
        ksort($routes);

        $pages = [];
        foreach ($this->byPage as $key => $boundaries) {
            $route = explode("\0", $key, 2)[0];
            foreach ($boundaries as $boundary) {
                $pages[$route][] = [...$boundary, 'params' => $this->pageParams[$key] ?? []];
            }
        }
        ksort($pages);

        return ['format' => self::FORMAT, 'routes' => $routes, 'pages' => $pages, 'queries' => $queries];
    }

    /**
     * Writes the manifest where fromFile() will read it, whole: written under
     * a private name and renamed into place, so a worker starting meanwhile
     * reads the old manifest or the new one, never half of either.
     */
    public function write(string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} for the fragment manifest.");
        }

        $json = json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $json) !== \strlen($json) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException("Cannot write the fragment manifest to {$path}.");
        }
    }

    /**
     * Drops one built page's boundaries, for StaticBuilder::rebuild() to
     * declare the ones it built again in their place. The route still defers.
     *
     * @param array<string, string|int> $params the params the page was built for
     */
    public function forget(string $route, array $params = []): self
    {
        $page = self::pageKey($route, $params);
        unset($this->byPage[$page], $this->pageParams[$page]);

        return $this;
    }

    /**
     * @param array<string, mixed>  $props  what the build gave this boundary
     * @param array<string, string|int> $params the params the page was built for
     */
    public function declare(
        string $route,
        string $id,
        string $component,
        array $props = [],
        array $params = [],
    ): self {
        if ($id === '') {
            throw new \InvalidArgumentException('A declared boundary needs an id to be addressed by.');
        }

        $page = self::pageKey($route, $params);

        foreach ($this->byPage[$page] ?? [] as $existing) {
            if ($existing['id'] === $id) {
                // Two boundaries under one id would make "mint the token for
                // boundary X" ambiguous, and the client could not tell which
                // token it got.
                throw new \InvalidArgumentException(
                    "Route '{$route}' declares two deferred boundaries with id '{$id}'.",
                );
            }
        }

        $this->byPage[$page][] = ['id' => $id, 'component' => $component, 'props' => $props];
        $this->pageParams[$page] = self::paramStrings($params);
        $this->deferring[$route] = true;

        return $this;
    }

    /**
     * A route whose pages contain a <Deferred> boundary, so a render of it
     * may mint fragment tokens.
     *
     * By route rather than by page, unlike declare(): a dynamic page's
     * boundaries and props exist only while it renders. The renderer names
     * them then; this says only that the route has any, so the renders of
     * every other route get no minter at all.
     *
     * @param list<string>|null $components the components its boundaries
     *        name, as the build read them; null when it could not read them
     *        all, which allows any. Declared twice, a route allows what
     *        either declaration does.
     */
    public function declareRoute(string $route, ?array $components = null): self
    {
        if ($route === '') {
            throw new \InvalidArgumentException('A route that defers needs a name to be found by.');
        }

        if (!\array_key_exists($route, $this->components)) {
            $this->components[$route] = $components === null ? null : array_values(array_unique($components));
        } else {
            $existing = $this->components[$route];
            $this->components[$route] = $existing === null || $components === null
                ? null
                : array_values(array_unique([...$existing, ...$components]));
        }
        $this->deferring[$route] = true;

        return $this;
    }

    /**
     * Whether a render of this route may mint: it was declared, or one of
     * its statically-built pages has a boundary.
     */
    public function defersOn(string $route): bool
    {
        return isset($this->deferring[$route]);
    }

    /**
     * The components a render of this route may mint for, or null for any.
     *
     * A list only when the build read every boundary the route's module
     * graph reaches. ADR 0009 names what this narrows: a renderer running
     * hostile code could have tokens signed "for subtrees the page did not
     * contain". With the boundary set known ahead of the render, a token can
     * name only a component the page does.
     *
     * @return list<string>|null
     */
    public function componentsFor(string $route): ?array
    {
        return $this->components[$route] ?? null;
    }

    /**
     * The query names a route's renders may call -- its page's, its
     * fragments', its re-renders' -- as the build found them in the route's
     * server-component graph. Declared twice, a route may call what either
     * declaration lists.
     *
     * @param list<string> $queries
     */
    public function declareQueries(string $route, array $queries): self
    {
        if ($route === '') {
            throw new \InvalidArgumentException('A query allowlist belongs to a route, found by its name.');
        }

        $names = array_values(array_unique([...$this->queries[$route] ?? [], ...$queries]));
        sort($names, SORT_STRING);
        $this->queries[$route] = $names;

        return $this;
    }

    /**
     * What a render of this route may call: "PHP rejects any other name on
     * that route's socket". Empty for a route the build did not list -- one
     * it never saw, or an unnamed page's '' -- so a route the manifest does
     * not know is allowed nothing, never everything.
     *
     * @return list<string>
     */
    public function queriesFor(string $route): array
    {
        return $this->queries[$route] ?? [];
    }

    /**
     * Every route whose renders may mint, by name.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return array_keys($this->deferring);
    }

    /**
     * One built page: a route and the exact params it was built for.
     *
     * Params are sorted so the key does not depend on the order a caller
     * happened to send them in, and the separators cannot appear in a
     * route name or a param value without being escaped by json_encode.
     *
     * @param array<array-key, mixed> $params
     */
    private static function pageKey(string $route, array $params): string
    {
        $params = self::paramStrings($params);
        ksort($params);

        return $route . "\0" . json_encode($params, JSON_THROW_ON_ERROR);
    }

    /**
     * Params as strings, which is how a route carries them.
     *
     * The build writes this manifest as JSON, where a numeric param is
     * naturally a number, and the bootstrap endpoint stringifies every param
     * before it looks a page up. Keyed as given, `"page": 2` made a page no
     * request could ever match. Anything but a string or an integer is not a
     * route param at all, so it is a broken build rather than a page.
     *
     * @param array<array-key, mixed> $params
     * @return array<array-key, string>
     */
    private static function paramStrings(array $params): array
    {
        $strings = [];
        foreach ($params as $name => $value) {
            if (!\is_string($value) && !\is_int($value)) {
                throw new \InvalidArgumentException(
                    "Param '{$name}' of a built page must be a string or an integer, not " . get_debug_type($value) . '.',
                );
            }
            $strings[$name] = (string) $value;
        }

        return $strings;
    }

    /**
     * The boundaries one built page declares, or none.
     *
     * The params must be the ones the page was BUILT with, exactly. A page
     * built for a different entity is a different page, and pairing its
     * recorded props with these params would mint a token describing a
     * combination that was never rendered.
     *
     * An unknown page is empty rather than an error: it is the ordinary
     * answer for a page that was not built statically, and distinguishing
     * "no such route" from "no boundaries" would tell a caller which routes
     * exist.
     *
     * @param array<string, string|int> $params
     * @return list<array{id: string, component: string, props: array<string, mixed>}>
     */
    public function boundariesFor(string $route, array $params = []): array
    {
        return $this->byPage[self::pageKey($route, $params)] ?? [];
    }

    /**
     * Whether any statically-built page has a boundary -- what the bootstrap
     * endpoint serves. A route declared by name alone is rendered
     * dynamically and mints as it renders, so it needs no bootstrap.
     */
    public function hasAny(): bool
    {
        return $this->byPage !== [];
    }

    /**
     * The routes with a statically-built page, each named once however many
     * pages the build made of it.
     *
     * @return list<string>
     */
    public function pages(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode("\0", $key)[0],
            array_keys($this->byPage),
        )));
    }
}
