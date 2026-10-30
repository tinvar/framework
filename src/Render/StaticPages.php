<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Orm\Orm;

/**
 * Static pages kept fresh where they are served: revalidation.
 *
 * tinvar-spec.md names the output twice -- "Stored output (static,
 * revalidated) never contains a CSRF token" -- and a page built once is as
 * of its build. Two things make it stale, and each has its answer here:
 *
 * - **Time.** `#[StaticPage(revalidate: '10m')]`: a page whose file is older
 *   than that is built again by revalidate() -- `tinvar static:revalidate`,
 *   or the RevalidateStaticPages task, every minute.
 * - **A change.** `#[StaticPage(tags: ['products'])]`: an action that
 *   invalidates one of them, once its writes commit, takes the page out of
 *   service at once (invalidate()). Nothing serves the stale file: a server
 *   finds none, and PHP renders the page fresh for whoever asks, until the
 *   next revalidate() builds it again. Faster than waiting for the build,
 *   and never wrong while it waits.
 *
 * Built only ever by StaticBuilder::rebuild(), which renders as nobody and
 * holds the document to carrying nothing of anyone's, exactly as `tinvar
 * build:static` does. invalidate() renders nothing: it is safe from inside
 * any request.
 *
 * A page invalidated while it was being built is not moved into place -- what
 * it was rendered from may be what the action changed -- and stays out of
 * service for the next run. Each invalidation leaves a marker, beside the
 * directory rather than in it, so nothing serves it.
 */
final class StaticPages
{
    /**
     * @param string $directory where the pages are served from
     * @param string $manifest the fragment manifest the pages' boundaries are declared in
     * @param (\Closure(): int)|null $clock the time now, for tests
     */
    public function __construct(
        private readonly StaticBuilder $builder,
        public readonly string $directory,
        public readonly string $manifest,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * The pages to build again now: missing -- invalidated, or never built
     * -- older than their revalidate interval, or invalidated with no build
     * since to answer it, whatever their file: a run that died between
     * moving a page in and taking it out again left one serving.
     *
     * @return list<string> their paths
     */
    public function stale(): array
    {
        $now = $this->now();
        $stale = [];
        foreach ($this->builder->pages() as $page) {
            $file = StaticBuilder::fileOf($this->directory, $page['path']);
            $marker = $this->markerOf($page['path']);
            clearstatcache(true, $file);
            clearstatcache(true, $marker);
            $built = is_file($file) ? filemtime($file) : false;
            if ($built === false || is_file($marker) || ($page['revalidate'] !== null && $built + $page['revalidate'] <= $now)) {
                $stale[] = $page['path'];
            }
        }

        return $stale;
    }

    /**
     * Builds the pages given -- every stale one when none are -- in place.
     *
     * @param list<string>|null $paths
     * @return array{built: list<string>, failed: array<string, string>}
     */
    public function revalidate(?array $paths = null): array
    {
        $paths ??= $this->stale();
        if ($paths === []) {
            return ['built' => [], 'failed' => []];
        }

        $started = $this->now();
        // Each page asked as soon as it is in place, against when its own
        // render began; and every page once more below, against the run's.
        $result = $this->builder->rebuild($this->directory, $this->manifest, $paths, function (string $path): \Closure {
            $since = $this->now();

            return fn (): bool => $this->invalidatedSince($path, $since);
        });

        // Invalidated while it was being built: rendered, perhaps, from what
        // the invalidating action changed. Out of service again, for the
        // next run; a marker from before the build began has been answered.
        $built = [];
        foreach ($result['built'] as $path) {
            $marker = $this->markerOf($path);
            clearstatcache(true, $marker);
            $invalidated = is_file($marker) ? filemtime($marker) : false;
            if ($invalidated !== false && $invalidated >= $started) {
                @unlink(StaticBuilder::fileOf($this->directory, $path));
                $result['failed'][$path] = 'invalidated while it was built; built again on the next run';

                continue;
            }
            if ($invalidated !== false) {
                @unlink($marker);
            }
            $built[] = $path;
        }

        return ['built' => $built, 'failed' => $result['failed']];
    }

    /**
     * Takes every page tagged with any of $tags out of service: its file
     * removed, so a server sends the request to PHP, and a marker left so a
     * build already under way does not put it back.
     *
     * Call once the writes behind the tags are committed (Orm::afterCommit):
     * before, a build could read the old rows and serve them as fresh.
     *
     * @param list<string> $tags
     * @return list<string> the paths taken out of service
     */
    public function invalidate(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $taken = [];
        foreach ($this->builder->pages() as $page) {
            if (array_intersect($page['tags'], $tags) === []) {
                continue;
            }

            $marker = $this->markerOf($page['path']);
            if (!is_dir(\dirname($marker))) {
                @mkdir(\dirname($marker), 0o755, true);
            }
            @touch($marker, $this->now());

            $file = StaticBuilder::fileOf($this->directory, $page['path']);
            if (is_file($file) && !@unlink($file) && is_file($file)) {
                throw new \RuntimeException("Cannot take the static page {$page['path']} out of service.");
            }
            $taken[] = $page['path'];
        }

        return $taken;
    }

    /**
     * invalidate(), once the writes behind $tags commit -- for a write in
     * one of $tenants -- and only where one of them is no tenant: a static
     * page is rendered as no tenant's, and a tenant's tags name that
     * tenant's rows, so one tenant's write is no reason to take everyone's
     * pages down. After the commit, so no build in between reads the old
     * rows and serves them as fresh.
     *
     * What every invalidation takes static pages down through -- an
     * action's, an endpoint's, an import's (TagInvalidation) and a job's or
     * a task's (Live\Invalidator), ADR 0106. A page that could not be taken
     * down is logged, as a live update that could not be published is, and
     * serves on until its next revalidation: the writes are committed and
     * the answer is theirs.
     *
     * @param list<string> $tags
     * @param list<string> $tenants every tenant the write ran as
     */
    public function invalidateAfterCommit(Orm $orm, array $tags, array $tenants): void
    {
        if ($tags === [] || !\in_array('', $tenants, true)) {
            return;
        }

        $orm->afterCommit(function () use ($tags): void {
            try {
                $this->invalidate($tags);
            } catch (\Throwable $e) {
                error_log('[tinvar] a static page could not be taken out of service: ' . $e->getMessage());
            }
        });
    }

    /** Whether the page at $path was invalidated at $since or after. */
    private function invalidatedSince(string $path, int $since): bool
    {
        $marker = $this->markerOf($path);
        clearstatcache(true, $marker);
        $invalidated = is_file($marker) ? filemtime($marker) : false;

        return $invalidated !== false && $invalidated >= $since;
    }

    private function markerOf(string $path): string
    {
        return rtrim($this->directory, '/') . '.invalidated/' . hash('sha256', $path);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
