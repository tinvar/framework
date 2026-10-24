<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Attributes\StaticPage;
use Tinvar\Http\Request;
use Tinvar\Routing\RouteRegistry;

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
     * @return list<array{route: string, params: array<string, string>, path: string}>
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

            foreach ($attributes[0]->newInstance()->params as $given) {
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

                $pages[] = ['route' => $page->name, 'params' => $params, 'path' => $this->routes->url($page->name, $params)];
            }
        }

        return $pages;
    }

    /**
     * Renders every page into $directory -- `<path>/index.html` each --
     * replacing whatever was there, and declares each page's boundaries in
     * $manifest.
     *
     * Built aside and moved into place at the end: a server reading the
     * directory meanwhile serves the old pages or the new ones, and a page
     * that fails leaves the old ones as they were.
     *
     * @return list<string> the paths built
     */
    public function build(string $directory, FragmentManifest $manifest): array
    {
        $directory = rtrim($directory, '/');
        $staging = $directory . '.building-' . bin2hex(random_bytes(4));
        if (!@mkdir($staging, 0o755, true)) {
            throw new \RuntimeException("Cannot create {$staging} to build static pages in.");
        }

        try {
            $built = [];
            foreach ($this->pages() as $page) {
                $minter = new StaticMinter($page['route'], $page['params'], $manifest->componentsFor($page['route']));
                // No cookies, no address: a visitor the server has never met.
                $html = ($this->render)(new Request('GET', $page['path'], ['accept' => 'text/html']), $minter->closure());

                if ($minter->refused() !== []) {
                    throw new \RuntimeException(
                        "{$page['path']} has boundaries a page render would refuse: " . implode('; ', $minter->refused()),
                    );
                }
                self::assertNothingPerVisitor($html, $page['path']);

                foreach ($minter->boundaries() as $boundary) {
                    $manifest->declare($page['route'], $boundary['id'], $boundary['component'], $boundary['props'], $page['params']);
                }

                $file = $staging . rtrim($page['path'], '/') . '/index.html';
                if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o755, true)) {
                    throw new \RuntimeException("Cannot create the directory for {$page['path']}.");
                }
                if (@file_put_contents($file, $html) !== \strlen($html)) {
                    throw new \RuntimeException("Cannot write {$file}.");
                }
                $built[] = $page['path'];
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
