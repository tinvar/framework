<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * Where renders go: one renderer's socket, or a pool -- a directory holding
 * one socket per renderer process, as `renderer.js --processes=N` keeps it
 * (ADR 0028).
 *
 * A pool is listed for every render rather than once: its renderers come
 * and go under it. One that dies has its socket removed and is replaced
 * under a new name, and a rolling restart adds each replacement before the
 * renderer it replaces removes its own. A listing costs a directory read;
 * a stale one sends renders to sockets that are gone.
 *
 * Renderers are taken in turn, from a point each instance picks at random.
 * Every FrankenPHP worker thread has its own channel, and threads that all
 * began at the first socket would pile onto it together.
 */
final class RendererSockets
{
    private int $turn;

    public function __construct(public readonly string $path)
    {
        $this->turn = random_int(0, 1 << 20);
    }

    /** Whether the path names a pool, as it stands now. */
    public function isPool(): bool
    {
        clearstatcache(true, $this->path);

        return is_dir($this->path);
    }

    /**
     * The sockets to try for one render, in order: the renderer whose turn it
     * is, then each of the others, for when it refuses.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        if (!$this->isPool()) {
            return [$this->path];
        }

        // Sorted, so the order is the same from one listing to the next and
        // taking turns goes round every renderer. Not glob(): a directory
        // name with a bracket in it would be read as a pattern.
        $directory = rtrim($this->path, '/');
        $sockets = [];
        foreach (@scandir($directory) ?: [] as $name) {
            if (str_ends_with($name, '.sock')) {
                $sockets[] = "{$directory}/{$name}";
            }
        }
        if ($sockets === []) {
            return [];
        }

        $first = $this->turn++ % \count($sockets);

        return [...\array_slice($sockets, $first), ...\array_slice($sockets, 0, $first)];
    }
}
