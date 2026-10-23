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

    /**
     * @param array<string, list<array{
     *     id: string,
     *     component: string,
     *     props?: array<string, mixed>,
     *     params?: array<string, string>
     * }>> $boundaries route name => the boundaries that route's built pages contain
     */
    public function __construct(array $boundaries = [])
    {
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
     * Reads a manifest the build wrote.
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
            return new self();
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read the fragment manifest at {$path}.");
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("The fragment manifest at {$path} is not valid JSON.", previous: $e);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException("The fragment manifest at {$path} is not an object.");
        }

        /** @var array<string, list<array{id: string, component: string, props?: array<string, mixed>}>> $decoded */
        return new self($decoded);
    }

    /**
     * @param array<string, mixed>  $props  what the build gave this boundary
     * @param array<string, string> $params the params the page was built for
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

        return $this;
    }

    /**
     * One built page: a route and the exact params it was built for.
     *
     * Params are sorted so the key does not depend on the order a caller
     * happened to send them in, and the separators cannot appear in a
     * route name or a param value without being escaped by json_encode.
     *
     * @param array<string, string> $params
     */
    private static function pageKey(string $route, array $params): string
    {
        ksort($params);

        return $route . "\0" . json_encode($params, JSON_THROW_ON_ERROR);
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
     * @param array<string, string> $params
     * @return list<array{id: string, component: string, props: array<string, mixed>}>
     */
    public function boundariesFor(string $route, array $params = []): array
    {
        return $this->byPage[self::pageKey($route, $params)] ?? [];
    }

    public function hasAny(): bool
    {
        return $this->byPage !== [];
    }

    /** @return list<string> every built page, as "route" plus its params */
    public function pages(): array
    {
        return array_map(
            static fn (string $key): string => explode("\0", $key)[0],
            array_keys($this->byPage),
        );
    }
}
