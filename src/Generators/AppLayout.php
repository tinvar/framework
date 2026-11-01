<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * Where an application's generated files go: each class where composer's
 * psr-4 autoloading will look for it, and each view under the directory
 * the renderer's pages are in.
 *
 * Read from composer.json rather than assumed, so a class is written where
 * it will be loaded from: in this workspace `Shop\` is examples/shop/app/,
 * and where an application maps a deeper prefix elsewhere -- an older
 * skeleton's `App\Database\` to database/ -- the longest prefix that
 * matches a class decides, as composer's own does.
 *
 * Which folder each kind of class goes in is read from the application too
 * (area()): the HTTP kinds under Http\ and models under Database\Models\,
 * as the skeleton and the shop keep them (ADR 0148), or -- for an
 * application made before, with a Pages\ or Models\ folder of its own and
 * none of those -- where it already keeps them.
 *
 * Only the entries under the application's namespace count, and only those
 * inside the project: a generator writes into the application, never into a
 * package beside it, and never through a mapping that leaves the project
 * (`../elsewhere/`), whoever wrote it.
 */
final readonly class AppLayout
{
    /**
     * @param string                $root       the project directory, resolved
     * @param string                $namespace  the application's root namespace, as in Shop
     * @param array<string, string> $prefixes   psr-4 prefix (with its trailing `\`) => directory, resolved
     * @param string                $pages      where the views are, resolved
     * @param string                $types      where codegen writes generated.ts, resolved
     */
    private function __construct(
        public string $root,
        public string $namespace,
        private array $prefixes,
        public string $pages,
        public string $types,
        private string $composer,
    ) {}

    /** The kinds a generator writes that are the application's HTTP side. */
    private const array HTTP = ['Pages', 'Queries', 'Actions', 'Requests', 'Data'];

    /**
     * The namespace, under the application's, that a kind of class goes in:
     * `Http\Pages` for a page and `Database\Models` for a model, as the
     * skeleton lays an application out (ADR 0148). A domain segment the
     * developer gives follows it: Http\Pages\Orders\OrderDetail.
     *
     * An application already keeping a kind in the older flat folder --
     * app/Pages/, app/Models/ -- and not in the new one keeps getting it
     * there, so a generator never splits one kind across two folders. One
     * keeping it in neither gets the new folder if it uses the layout at all
     * (it has app/Http/), and the flat one otherwise. app/Database/ alone
     * does not count: an older skeleton maps App\Database\ to database/.
     *
     * @param string $kind Pages, Queries, Actions, Requests, Data or Models
     */
    public function area(string $kind): string
    {
        $structured = match (true) {
            $kind === 'Models' => 'Database\\Models',
            \in_array($kind, self::HTTP, true) => "Http\\{$kind}",
            default => $kind,
        };
        if ($structured === $kind) {
            return $kind;
        }

        return match (true) {
            $this->holds($structured) => $structured,
            $this->holds($kind) => $kind,
            $this->holds('Http') => $structured,
            default => $kind,
        };
    }

    /**
     * Whether a class named $short is under the namespace $area (relative
     * to the application's) anywhere but at $at, relative to $area: a file
     * `$short.php` in its folder or one below it.
     */
    public function namedElsewhere(string $area, string $short, string $at): bool
    {
        if (!$this->holds($area)) {
            return false;
        }
        $root = \dirname($this->pathOf("{$this->namespace}\\{$area}\\Probe"));
        $mine = $this->pathOf("{$this->namespace}\\{$area}\\{$at}");
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getFilename() === "{$short}.php" && $file->getPathname() !== $mine) {
                return true;
            }
        }

        return false;
    }

    /** Whether the folder a namespace under the application's maps to is there. */
    private function holds(string $relative): bool
    {
        try {
            return is_dir(\dirname($this->pathOf("{$this->namespace}\\{$relative}\\Probe")));
        } catch (GenerationRefused) {
            return false;
        }
    }

    /**
     * @param string $root      the project directory, holding composer.json
     * @param string $namespace the application's root namespace, as in Shop
     * @param string $pages     the directory its views are in
     * @param string $types     the directory `tinvar codegen` writes generated.ts to
     */
    public static function fromComposer(string $root, string $namespace, string $pages, string $types): self
    {
        $root = self::resolve($root);
        $composer = "{$root}/composer.json";
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
        if (!\is_array($json)) {
            throw new GenerationRefused("{$composer} is missing or not JSON; it is what says where classes go.");
        }

        $namespace = trim($namespace, '\\');
        $prefixes = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $map = $json[$section]['psr-4'] ?? [];
            foreach (\is_array($map) ? $map : [] as $prefix => $directories) {
                $prefix = (string) $prefix;
                if ($prefix !== "{$namespace}\\" && !str_starts_with($prefix, "{$namespace}\\")) {
                    continue;
                }

                // The first directory of a list: where composer looks first.
                $directory = \is_array($directories) ? ($directories[0] ?? null) : $directories;
                if (!\is_string($directory)) {
                    continue;
                }
                if (str_starts_with($directory, '/') || \in_array('..', explode('/', str_replace('\\', '/', $directory)), true)) {
                    throw new GenerationRefused(
                        "{$composer} maps {$prefix} to {$directory}, outside the project. A generator writes only inside it.",
                    );
                }

                $prefixes[$prefix] ??= self::resolve("{$root}/" . rtrim($directory, '/'));
            }
        }

        // Longest first, so `App\Database\` wins over `App\`.
        uksort($prefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return new self($root, $namespace, $prefixes, self::resolve($pages), self::resolve($types), $composer);
    }

    /**
     * The file a class of the application's goes in.
     *
     * @param string $class a class under the application's namespace, its segments already checked as names
     */
    public function pathOf(string $class): string
    {
        foreach ($this->prefixes as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                return $this->inside($directory, $directory . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php');
            }
        }

        throw GenerationRefused::unmapped($class, $this->composer);
    }

    /** The file a view is drawn by, its name already checked as one. */
    public function viewPath(string $view, string $extension = 'tsx'): string
    {
        return $this->inside($this->pages, "{$this->pages}/{$view}.{$extension}");
    }

    /**
     * How a view imports generated.ts: `../../types/generated` from
     * pages/racks/show.tsx, as the shop's pages do.
     */
    public function typesImport(string $view): string
    {
        $from = explode('/', \dirname($this->viewPath($view)));
        $to = explode('/', "{$this->types}/generated");

        $common = 0;
        while ($common < \count($from) && $common < \count($to) && $from[$common] === $to[$common]) {
            $common++;
        }

        $path = str_repeat('../', \count($from) - $common) . implode('/', \array_slice($to, $common));

        return str_starts_with($path, '../') ? $path : "./{$path}";
    }

    /**
     * A file of the application's own that a generator edits -- a Kernel, a
     * console, the renderer's route table -- named absolute or from the
     * project, once it is certain to be inside the project: its directory
     * as the filesystem has it. The file itself is not followed, so a
     * symbolic link there is still one, for the edit to refuse.
     */
    public function inProject(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : "{$this->root}/{$path}";

        return $this->inside($this->root, self::resolve(\dirname($absolute)) . '/' . basename($absolute));
    }

    /** $path relative to the project, for what the command prints. */
    public function relative(string $path): string
    {
        return str_starts_with($path, "{$this->root}/") ? substr($path, \strlen($this->root) + 1) : $path;
    }

    /**
     * $path, once it is certain to be in $directory -- by its spelling, and
     * by where its nearest existing ancestor really is, so a symbolic link
     * inside the application cannot carry a write out of it.
     */
    private function inside(string $directory, string $path): string
    {
        if (!str_starts_with(self::normalize($path), "{$directory}/") || !str_starts_with(self::resolve(\dirname($path)) . '/', "{$directory}/")) {
            throw GenerationRefused::outside($path, $directory);
        }

        return $path;
    }

    /**
     * A path made absolute and canonical: its nearest existing ancestor as
     * the filesystem has it (symbolic links followed, /tmp as /private/tmp
     * on macOS), and the rest as spelled, `.` and `..` taken out.
     */
    private static function resolve(string $path): string
    {
        $path = self::normalize(str_starts_with($path, '/') ? $path : getcwd() . "/{$path}");

        $rest = [];
        $existing = $path;
        while (($real = realpath($existing)) === false && $existing !== '/') {
            array_unshift($rest, basename($existing));
            $existing = \dirname($existing);
        }

        return rtrim(($real === false || $real === '/' ? '' : $real) . '/' . implode('/', $rest), '/');
    }

    /** `.` and `..` taken out of an absolute path, as spelled. */
    private static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
