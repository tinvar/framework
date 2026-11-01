<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Attributes\StaticPage;
use Tinvar\Features\FlagReads;
use Tinvar\Http\Request;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;

/**
 * `tinvar build:static`: every #[StaticPage] rendered to a file a server
 * sends without PHP rendering it.
 *
 * tinvar-spec.md: "Fragments inside static pages get their tokens from a
 * small per-visitor bootstrap request, never from the build", and "stored
 * output (static, revalidated) never contains a CSRF token". A file is sent
 * to everyone, so nothing in it may be anyone's.
 *
 * That is held two ways. Structurally: each page is requested with no
 * cookies at all, so there is no session to mint a route token, a fragment
 * token, a CSRF token or a history key for, and no user for a loader to read
 * -- the Kernel issues none of them to a visitor without one. And then
 * checked: the document is refused if its page metadata carries a route
 * token or a history key, if anything in it is shaped like a signed token, or
 * if a form's hidden `_token` field has a value. The check is the backstop
 * for an application's render doing something the request path did not.
 *
 * A page's <Deferred> boundaries are recorded by a StaticMinter and declared
 * in the FragmentManifest under the page's route and params, which is what
 * mounts /_bootstrap and what it mints from.
 *
 * build() writes the whole set, aside, and moves it into place at once;
 * rebuild() replaces pages one at a time where they are, for revalidation
 * (StaticPages), each through the same request as nobody and the same
 * checks.
 */
final class StaticBuilder
{
    /**
     * What a built page's params may hold. Each becomes a directory name, and
     * the server finds the file by the path the browser asked for: anything
     * that would be escaped in a URL, or could climb out of the directory,
     * has no file a request could name.
     */
    private const string PARAM_SHAPE = '/^[A-Za-z0-9][A-Za-z0-9._~-]{0,127}\z/';

    /** The page metadata's element; html-stream.js PAGE_METADATA_ID writes it. */
    private const string METADATA = '#<script type="application/json" id="tinvar-page">(.*?)</script>#s';

    /**
     * @param \Closure(Request, \Closure(string, array<string, mixed>): string): string $render
     *        renders one page to a document, through the application's whole
     *        request path, for the request given -- which carries no session
     *        -- handing the renderer the mint closure given
     */
    public function __construct(
        private readonly RouteRegistry $routes,
        private readonly \Closure $render,
    ) {}

    /**
     * Every page to build: each #[StaticPage] route, once per set of params
     * it lists.
     *
     * @return list<array{route: string, params: array<string, string>, path: string, revalidate: int|null, tags: list<string>}>
     */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->routes->pages() as $page) {
            $attributes = new \ReflectionClass($page->class)->getAttributes(StaticPage::class);
            if ($attributes === []) {
                continue;
            }

            // /_bootstrap is asked by route name, and a placeholder carries it.
            if ($page->name === null) {
                throw new \RuntimeException("{$page->class} is a #[StaticPage] with no route name; name it to build it.");
            }

            // Its key is the session's, and a file has no session.
            if ($page->encryptHistory === true) {
                throw new \RuntimeException(
                    "{$page->class} asks for its history encrypted, which needs a session's key; it cannot be built statically.",
                );
            }

            $static = $attributes[0]->newInstance();
            foreach ($static->from === null ? $static->params : self::paramsFrom($page->class, $static->from) as $given) {
                $params = [];
                foreach ($given as $name => $value) {
                    $value = (string) $value;
                    if (preg_match(self::PARAM_SHAPE, $value) !== 1) {
                        throw new \RuntimeException(
                            "{$page->class}: '{$value}' cannot name a built page; a param of one is letters, digits and . _ ~ -.",
                        );
                    }
                    $params[(string) $name] = $value;
                }

                $pages[] = [
                    'route' => $page->name,
                    'params' => $params,
                    'path' => $this->routes->url($page->name, $params),
                    'revalidate' => $static->revalidateAfter,
                    'tags' => $static->tags,
                ];
            }
        }

        return $pages;
    }

    /**
     * The Host a page is requested with: the primary host's, where the
     * application routes by host -- which refuses a request naming none,
     * and serves a static page on that host alone (ADR 0149).
     *
     * @return array<string, string>
     */
    private function primaryHost(): array
    {
        $domains = $this->routes->domains();

        return $domains === null ? [] : ['host' => (string) preg_replace('#^https?://#', '', $domains->origin(null))];
    }

    /**
     * The params a #[StaticPage(from: ...)] class lists; refused when the
     * class is no StaticParams, rather than built as some other class's.
     *
     * @return list<array<string, string|int>>
     */
    private static function paramsFrom(string $page, string $from): array
    {
        if (!class_exists($from) || !is_subclass_of($from, StaticParams::class)) {
            throw new \RuntimeException("{$page} builds the params {$from} lists, which is no " . StaticParams::class . '.');
        }

        // Each value is held to PARAM_SHAPE by pages(), as a listed one is.
        return new $from()->params();
    }

    /**
     * Renders every page into $directory -- `<path>/index.html` each --
     * replacing whatever was there, and declares each page's boundaries in
     * $manifest.
     *
     * Built aside and moved into place at the end: a server reading the
     * directory meanwhile serves the old pages or the new ones, and a page
     * that fails leaves the old ones as they were. The move is two renames
     * -- the old directory out, the new one in -- so a request in the
     * instant between them finds no page; PHP has no rename that exchanges
     * two directories, and a symlink swapped in their place would change
     * what a deployment copies.
     *
     * @param (\Closure(FragmentManifest): void)|null $publish handed the manifest
     *        once every page is built and its boundaries declared, and before
     *        any page goes live: where it is written for /_bootstrap, so a
     *        new page is never served before its boundaries can be. A throw
     *        leaves the old pages as they were.
     * @return list<string> the paths built
     */
    public function build(string $directory, FragmentManifest $manifest, ?\Closure $publish = null): array
    {
        $directory = rtrim($directory, '/');
        $staging = $directory . '.building-' . bin2hex(random_bytes(4));
        if (!@mkdir($staging, 0o755, true)) {
            throw new \RuntimeException("Cannot create {$staging} to build static pages in.");
        }

        try {
            $built = [];
            foreach ($this->pages() as $page) {
                [$html, $boundaries] = $this->renderPage($page, $manifest);

                foreach ($boundaries as $boundary) {
                    $manifest->declare($page['route'], $boundary['id'], $boundary['component'], $boundary['props'], $page['params']);
                }

                self::write(self::fileOf($staging, $page['path']), $html, $page['path']);
                $built[] = $page['path'];
            }

            if ($publish !== null) {
                $publish($manifest);
            }

            $previous = null;
            if (is_dir($directory)) {
                $previous = $directory . '.previous-' . bin2hex(random_bytes(4));
                if (!@rename($directory, $previous)) {
                    throw new \RuntimeException("Cannot move the previous static pages out of {$directory}.");
                }
            }
            if (!@rename($staging, $directory)) {
                if ($previous !== null) {
                    @rename($previous, $directory);
                }
                throw new \RuntimeException("Cannot move the static pages into {$directory}.");
            }
            if ($previous !== null) {
                self::remove($previous);
            }

            return $built;
        } catch (\Throwable $e) {
            self::remove($staging);

            throw $e;
        }
    }

    /**
     * Builds pages again where they are, one at a time: revalidation.
     *
     * Each page is rendered exactly as build() renders it -- as nobody,
     * through its whole request path, its document checked for anything of
     * anyone's -- and written beside its file and renamed over it, so a
     * server reads the old page or the new one, never half. One that fails
     * is left serving as it was, and the rest go on.
     *
     * Its boundaries are declared in the manifest at $manifestPath, read
     * fresh and written back before the page is moved in, so /_bootstrap --
     * which reads the file as it stands (FragmentManifest::refreshed()) --
     * knows every boundary the new page places. The boundaries of the page
     * it replaces are kept beside them until the next rebuild, so a visitor
     * who loaded the old page a moment before still gets its tokens; their
     * ids are drawn from what they name (StaticMinter::idOf()), so the two
     * pages' cannot be confused. Rebuilds take turns under a lock beside the
     * directory.
     *
     * @param list<string>|null $paths the pages to build again, by path; every
     *        one when null. A path that is no static page is reported as failed.
     * @param (\Closure(string): (\Closure(): bool))|null $watch told of each
     *        page as its render begins, by path; what it returns says, once
     *        the page has been moved in, whether it was invalidated since --
     *        and then it is taken out again at once, not left serving while
     *        the rest are built (StaticPages)
     * @return array{built: list<string>, failed: array<string, string>} what
     *         was built, and why each that was not
     */
    public function rebuild(string $directory, string $manifestPath, ?array $paths = null, ?\Closure $watch = null): array
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory} to build static pages in.");
        }

        $lock = @fopen($directory . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Cannot lock {$directory}.lock to build static pages in place.");
        }

        try {
            $pages = $this->pages();
            $failed = [];
            foreach ($paths ?? [] as $path) {
                if (!\in_array($path, array_column($pages, 'path'), true)) {
                    $failed[$path] = 'no #[StaticPage] is built at this path';
                }
            }

            $built = [];
            foreach ($pages as $page) {
                if ($paths !== null && !\in_array($page['path'], $paths, true)) {
                    continue;
                }

                try {
                    $invalidated = $watch === null ? null : $watch($page['path']);
                    $manifest = FragmentManifest::fromFile($manifestPath);
                    [$html, $boundaries] = $this->renderPage($page, $manifest);
                    $file = self::fileOf($directory, $page['path']);

                    // What the page now serving places, kept for the visitor
                    // who loaded it just before it is replaced.
                    $serving = is_file($file) ? self::placedIn((string) @file_get_contents($file), $page['route'], $page['params']) : [];
                    $fresh = array_column($boundaries, 'id');
                    $kept = array_filter(
                        $manifest->boundariesFor($page['route'], $page['params']),
                        static fn (array $b): bool => \in_array($b['id'], $serving, true) && !\in_array($b['id'], $fresh, true),
                    );

                    $manifest->forget($page['route'], $page['params']);
                    foreach ([...$boundaries, ...$kept] as $boundary) {
                        $manifest->declare($page['route'], $boundary['id'], $boundary['component'], $boundary['props'], $page['params']);
                    }
                    $manifest->write($manifestPath);

                    $temporary = \dirname($file) . '/.index.html.' . bin2hex(random_bytes(6));
                    self::write($temporary, $html, $page['path']);
                    if (!@rename($temporary, $file)) {
                        @unlink($temporary);
                        throw new \RuntimeException("Cannot move the new page into {$file}.");
                    }

                    // Asked once it is in place, so an invalidation that came
                    // while it rendered -- whose action's writes it may not
                    // show -- or as it was moved in takes it out again here,
                    // rather than once every page after it has been built.
                    if ($invalidated !== null && $invalidated()) {
                        @unlink($file);
                        $failed[$page['path']] = 'invalidated while it was built; built again on the next run';

                        continue;
                    }
                    $built[] = $page['path'];
                } catch (\Throwable $e) {
                    $failed[$page['path']] = $e::class . ': ' . $e->getMessage();
                }
            }

            return ['built' => $built, 'failed' => $failed];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Where a page's file is under a directory of built pages.
     */
    public static function fileOf(string $directory, string $path): string
    {
        return rtrim($directory, '/') . rtrim($path, '/') . '/index.html';
    }

    /**
     * One page, rendered as a visitor the server has never met and checked:
     * its document, and the boundaries it placed.
     *
     * @param array{route: string, params: array<string, string>, path: string} $page
     * @return array{string, list<array{id: string, component: string, props: array<string, mixed>}>}
     */
    private function renderPage(array $page, FragmentManifest $manifest): array
    {
        // The components the renderer build found the route's boundaries
        // naming -- and none at all for a route it found no boundary on, as
        // a page render of that route gets no minter (ADR 0009). Taken for
        // "any", what the renderer asked was recorded, and the route went
        // into the manifest as one whose renders may mint anything.
        $components = $manifest->defersOn($page['route']) ? $manifest->componentsFor($page['route']) : [];
        $minter = new StaticMinter($page['route'], $page['params'], $components);
        // No cookies, no address: a visitor the server has never met. And
        // every feature flag the render reads, in any fiber: a file is sent
        // to everyone, so it cannot be one tenant's, user's or panel's
        // variant -- nor keyed by a flag, since nothing asks for it but the
        // path (ADR 0094).
        $flags = new FlagReads();
        $request = new Request('GET', $page['path'], ['accept' => 'text/html', ...$this->primaryHost()]);
        $html = $flags->span(fn (): string => ($this->render)($request, $minter->closure()));
        if (!$flags->clean()) {
            throw new \RuntimeException(
                "{$page['path']} read the feature flag(s) " . (implode(', ', array_keys($flags->flags())) ?: 'it cannot name')
                . ', and a static page is sent to everyone whatever their flags. Render it per request, or read the flag in a fragment.',
            );
        }

        if ($minter->refused() !== []) {
            throw new \RuntimeException(
                "{$page['path']} has boundaries a page render would refuse: " . implode('; ', $minter->refused()),
            );
        }
        self::assertNothingPerVisitor($html, $page['path']);

        return [$html, $minter->boundaries()];
    }

    /**
     * The ids of the boundaries a built document places for its own page:
     * the `b` of each placeholder naming this route and these params.
     *
     * @param array<string, string> $params
     * @return list<string>
     */
    private static function placedIn(string $html, string $route, array $params): array
    {
        preg_match_all('/' . preg_quote(StaticMinter::PREFIX, '/') . '([A-Za-z0-9_-]+)/', $html, $matches);

        $ids = [];
        foreach ($matches[1] as $encoded) {
            $placeholder = json_decode((string) Signer::decode($encoded), true);
            if (\is_array($placeholder) && ($placeholder['r'] ?? null) === $route && ($placeholder['p'] ?? null) == $params
                && \is_string($placeholder['b'] ?? null)) {
                $ids[] = $placeholder['b'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function write(string $file, string $html, string $path): void
    {
        if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o755, true) && !is_dir(\dirname($file))) {
            throw new \RuntimeException("Cannot create the directory for {$path}.");
        }
        if (@file_put_contents($file, $html) !== \strlen($html)) {
            @unlink($file);
            throw new \RuntimeException("Cannot write {$file}.");
        }
    }

    /**
     * Refuses a document carrying anything that belongs to one visitor.
     *
     * @throws \RuntimeException naming what was found, never its value
     */
    public static function assertNothingPerVisitor(string $html, string $path): void
    {
        if (preg_match(self::METADATA, $html, $m) === 1) {
            $metadata = json_decode($m[1], true);
            if (!\is_array($metadata) || ($metadata['routeToken'] ?? null) !== null || \array_key_exists('historyKey', $metadata)) {
                throw new \RuntimeException("{$path} was rendered with a route token or a history key, which are a session's.");
            }
        }

        // A fragment or route token: `v1.`, a payload and a MAC.
        if (preg_match('/v1\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/', $html) === 1) {
            throw new \RuntimeException("{$path} carries a signed token, which would be every visitor's.");
        }

        // The hidden field a form posts without script, filled in.
        if (preg_match('/<input\b(?=[^>]*\bname="_token")(?=[^>]*\bvalue="[^"]+")[^>]*>/i', $html) === 1) {
            throw new \RuntimeException("{$path} carries a CSRF token in a form, which belongs to one session.");
        }
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
