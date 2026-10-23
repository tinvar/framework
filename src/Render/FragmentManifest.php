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
    /** @var array<string, list<array{id: string, component: string, props: array<string, mixed>}>> */
    private array $byRoute = [];

    /**
     * @param array<string, list<array{id: string, component: string, props?: array<string, mixed>}>> $boundaries
     *        route name => the boundaries that route's built HTML contains
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

    /** @param array<string, mixed> $props */
    public function declare(string $route, string $id, string $component, array $props = []): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A declared boundary needs an id to be addressed by.');
        }

        foreach ($this->byRoute[$route] ?? [] as $existing) {
            if ($existing['id'] === $id) {
                // Two boundaries under one id would make "mint the token for
                // boundary X" ambiguous, and the client could not tell which
                // token it got.
                throw new \InvalidArgumentException(
                    "Route '{$route}' declares two deferred boundaries with id '{$id}'."
                );
            }
        }

        $this->byRoute[$route][] = ['id' => $id, 'component' => $component, 'props' => $props];

        return $this;
    }

    /**
     * The boundaries a route declares, or none.
     *
     * An unknown route is empty rather than an error: it is the ordinary
     * answer for a page that was not built statically, and distinguishing
     * "no such route" from "no boundaries" would tell a caller which routes
     * exist.
     *
     * @return list<array{id: string, component: string, props: array<string, mixed>}>
     */
    public function boundariesFor(string $route): array
    {
        return $this->byRoute[$route] ?? [];
    }

    public function hasAny(): bool
    {
        return $this->byRoute !== [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return array_keys($this->byRoute);
    }
}
