<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application's own classes, as its composer.json's PSR-4 autoload
 * maps them: what tenant-scoped-ids-must-be-typed looks in for a model a
 * bare id names that no list holds and no listed or taken model sits beside
 * (ADR 0122), and what discovery reads every class the application
 * registers from (ADR 0146).
 *
 * A list kept by hand is a list a new model is missing from, and a model
 * in a namespace of its own was found by nothing. Composer's map is what
 * the application loads its classes by, so a model it can load is a model
 * this finds: by its file's name, `Site.php` for `siteId`, under each
 * directory a prefix maps -- the root package's `autoload`, never
 * `autoload-dev`, whose test fixtures are not the application's, and never
 * a dependency's.
 *
 * Read from the file system, not by loading every class: the directories
 * are walked once, by file name, and only a file whose name a bare id
 * names, and whose tokens declare a class of that name in the namespace
 * its path says, is handed back to be loaded and asked whether it is a
 * #[TenantScoped] model. A file named like a class that declares no such
 * class is never run; one that does is included whole, so whatever code
 * it holds outside the class runs when it is loaded, as it would when the
 * application autoloads it. A
 * walk follows no symbolic link out of the tree, walks no directory of
 * what Composer or npm installed, of tests or of fixtures, and stops at
 * MAX_FILES: saying so once, as a warning, and to `tinvar check`, which
 * fails on it (check()), since a model past the cap is one nothing finds.
 */
final class ApplicationClasses
{
    /** The most files one walk reads the names of, past which it stops. */
    public const int MAX_FILES = 50_000;

    /** Directories never walked: what Composer and npm install, and tests and their fixtures, which are not the application's. */
    private const array SKIPPED = ['vendor', 'node_modules', 'tests', 'fixtures'];

    private static ?self $composer = null;

    /** @var array<string, list<array{string, string}>>|null by lowercased short name, every class under the map and its file */
    private ?array $index = null;

    /** @var list<array{string, string}>|null every class the map's paths name, and its file */
    private ?array $files = null;

    /** Whether the walk stopped at the cap, with files left unread. */
    private bool $truncated = false;

    /**
     * @param array<string, list<string>> $psr4 namespace prefix ('App\\') => the directories it maps
     * @param int $maxFiles the most files the walk reads the names of
     */
    public function __construct(private readonly array $psr4, private readonly int $maxFiles = self::MAX_FILES) {}

    /**
     * The root package's PSR-4 map, read once per process from its
     * composer.json; none where Composer's runtime cannot say which
     * package is the root, or its file does not read.
     */
    public static function fromComposer(): self
    {
        if (self::$composer !== null) {
            return self::$composer;
        }

        $root = class_exists(\Composer\InstalledVersions::class)
            ? realpath(\Composer\InstalledVersions::getRootPackage()['install_path'])
            : false;

        return self::$composer = $root === false ? new self([]) : self::fromComposerJson($root);
    }

    /**
     * The PSR-4 map of the composer.json in $root: its `autoload`, never
     * its `autoload-dev`; none where the file does not read.
     */
    public static function fromComposerJson(string $root): self
    {
        $file = "{$root}/composer.json";
        $json = is_file($file) ? file_get_contents($file) : false;
        $manifest = $json === false ? null : json_decode($json, true);
        $map = \is_array($manifest) && \is_array($manifest['autoload'] ?? null) && \is_array($manifest['autoload']['psr-4'] ?? null)
            ? $manifest['autoload']['psr-4']
            : [];
        $psr4 = [];
        foreach ($map as $prefix => $directories) {
            $paths = [];
            foreach (\is_array($directories) ? $directories : [$directories] as $directory) {
                if (\is_string($directory)) {
                    $paths[] = rtrim("{$root}/{$directory}", '/');
                }
            }
            if (\is_string($prefix) && $paths !== []) {
                $psr4[$prefix] = $paths;
            }
        }

        return new self($psr4);
    }

    /**
     * The map's prefixes that are $namespace or under it, and nothing
     * else: one application's classes, where a workspace's composer.json
     * maps several (ADR 0146).
     */
    public function under(string $namespace): self
    {
        $namespace = strtolower(trim($namespace, '\\'));
        $psr4 = [];
        foreach ($this->psr4 as $prefix => $directories) {
            $trimmed = strtolower(trim($prefix, '\\'));
            if ($trimmed === $namespace || str_starts_with($trimmed, "{$namespace}\\")) {
                $psr4[$prefix] = $directories;
            }
        }

        return new self($psr4, $this->maxFiles);
    }

    /**
     * The map: each prefix and the directories it maps.
     *
     * @return array<string, list<string>>
     */
    public function prefixes(): array
    {
        return $this->psr4;
    }

    /**
     * Every PHP file under the map whose path names a class, with the
     * class it names: the prefix, then the path below its directory. Read
     * from the file system alone, nothing loaded; sorted by file. A
     * directory of what Composer or npm installed, of tests or fixtures,
     * or a dot-directory, is never walked, and no symbolic link is
     * followed; the walk stops at the cap (truncated()).
     *
     * @return list<array{string, string}> the class its path names, and the file
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $found = [];
        $count = 0;
        foreach ($this->psr4 as $prefix => $directories) {
            $prefix = trim($prefix, '\\');
            foreach ($directories as $directory) {
                $base = realpath($directory);
                if ($base === false || !is_dir($base)) {
                    continue;
                }
                $walk = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                    static fn (\SplFileInfo $file): bool => !$file->isDir()
                        || (!$file->isLink() && !\in_array(strtolower($file->getFilename()), self::SKIPPED, true) && !str_starts_with($file->getFilename(), '.')),
                ));
                foreach ($walk as $file) {
                    if (++$count > $this->maxFiles) {
                        $this->truncated = true;
                        break 3;
                    }
                    if (!$file instanceof \SplFileInfo || $file->isLink() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $relative = substr($file->getPathname(), \strlen($base) + 1, -4);
                    $name = str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);
                    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name) !== 1) {
                        continue;
                    }
                    $found[] = [ltrim("{$prefix}\\{$name}", '\\'), $file->getPathname()];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => strcmp($a[1], $b[1]) ?: strcmp($a[0], $b[0]));

        return $this->files = $found;
    }

    /**
     * The classes named $short -- a file `{$short}.php` -- under a prefix
     * in one of $roots, the top namespaces whose classes are looked at:
     * the application's, as its models and entry points name it. Sorted,
     * so the same one is found first every time.
     *
     * @param list<string> $roots
     * @return list<string>
     */
    public function named(string $short, array $roots): array
    {
        $found = [];
        foreach ($this->index()[strtolower($short)] ?? [] as [$class, $file]) {
            $top = strstr($class, '\\', true);
            if ($top !== false && \in_array($top, $roots, true) && self::declares($file, $class)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /** Whether the walk stopped at the cap: a class past it is not found. */
    public function truncated(): bool
    {
        $this->index();

        return $this->truncated;
    }

    /**
     * Whether files() read every file under the map, without the warning
     * truncated() gives: for a caller that fails on it itself.
     */
    public function walkedWhole(): bool
    {
        $this->files();

        return !$this->truncated;
    }

    /**
     * What `tinvar check` says of the map: nothing, or that the walk
     * stopped at the cap, so tenant-scoped-ids-must-be-typed could not look
     * at every class it maps.
     *
     * @return list<string>
     */
    public function check(): array
    {
        return $this->truncated() ? [self::capped($this->maxFiles)] : [];
    }

    /**
     * Whether $file's tokens declare $class -- its namespace, then a class,
     * an interface, a trait or an enum of its short name -- read without
     * running it.
     */
    private static function declares(string $file, string $class): bool
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return false;
        }
        $slash = strrpos($class, '\\');
        $namespace = $slash === false ? '' : substr($class, 0, $slash);
        $short = $slash === false ? $class : substr($class, $slash + 1);

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => !\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $in = '';
        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                $in = \is_array($next) && \in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true) ? $next[1] : '';
                continue;
            }
            if (\in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = $tokens[$i - 1] ?? null;
                if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue; // Foo::class
                }
                $next = $tokens[$i + 1] ?? null;
                if (\is_array($next) && $next[0] === T_STRING && strcasecmp($next[1], $short) === 0 && strcasecmp($in, $namespace) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function capped(int $max): string
    {
        return "tenant-scoped-ids-must-be-typed: the application's composer.json autoload map holds more files than the walk reads, and it "
            . "stopped at {$max} files: a #[TenantScoped] model past them is found by nothing. Narrow the map's directories.";
    }

    /**
     * Whether the map holds a prefix under one of $roots: whether named()
     * could find anything there at all.
     *
     * @param list<string> $roots
     */
    public function maps(array $roots): bool
    {
        foreach (array_keys($this->psr4) as $prefix) {
            if (\in_array(explode('\\', trim($prefix, '\\'))[0], $roots, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array{string, string}>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach ($this->files() as [$class, $file]) {
            $index[strtolower(basename($file, '.php'))][] = [$class, $file];
        }
        foreach ($index as $short => $classes) {
            usort($classes, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            $index[$short] = $classes;
        }
        // Loudly, where no check reads it: a registry at boot.
        if ($this->truncated) {
            trigger_error(self::capped($this->maxFiles), E_USER_WARNING);
        }

        return $this->index = $index;
    }
}
