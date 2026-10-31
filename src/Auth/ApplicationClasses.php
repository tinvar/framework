<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application's own classes by short name, as its composer.json's PSR-4
 * autoload maps them: what tenant-scoped-ids-must-be-typed looks in for a
 * model a bare id names that no list holds and no listed or taken model
 * sits beside (ADR 0122).
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
 * #[TenantScoped] model -- a script named like a class is never run. A
 * walk follows no symbolic link out of the tree, and stops at MAX_FILES:
 * saying so once, as a warning, and to `tinvar check`, which fails on it
 * (check()), since a model past the cap is one nothing finds.
 */
final class ApplicationClasses
{
    /** The most files one walk reads the names of, past which it stops. */
    public const int MAX_FILES = 50_000;

    private static ?self $composer = null;

    /** @var array<string, list<array{string, string}>>|null by lowercased short name, every class under the map and its file */
    private ?array $index = null;

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

        $psr4 = [];
        if (class_exists(\Composer\InstalledVersions::class)) {
            $root = realpath(\Composer\InstalledVersions::getRootPackage()['install_path']);
            $file = $root === false ? null : "{$root}/composer.json";
            $json = $file === null || !is_file($file) ? false : file_get_contents($file);
            $manifest = $json === false ? null : json_decode($json, true);
            $map = \is_array($manifest) && \is_array($manifest['autoload'] ?? null) && \is_array($manifest['autoload']['psr-4'] ?? null)
                ? $manifest['autoload']['psr-4']
                : [];
            foreach ($map as $prefix => $directories) {
                $paths = [];
                foreach (\is_array($directories) ? $directories : [$directories] as $directory) {
                    if (\is_string($directory)) {
                        $paths[] = "{$root}/{$directory}";
                    }
                }
                if (\is_string($prefix) && $paths !== []) {
                    $psr4[$prefix] = $paths;
                }
            }
        }

        return self::$composer = new self($psr4);
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
        $files = 0;
        foreach ($this->psr4 as $prefix => $directories) {
            $prefix = trim($prefix, '\\');
            foreach ($directories as $directory) {
                $base = realpath($directory);
                if ($base === false || !is_dir($base)) {
                    continue;
                }
                $walk = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                );
                foreach ($walk as $file) {
                    if (++$files > $this->maxFiles) {
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
                    $class = ltrim("{$prefix}\\{$name}", '\\');
                    $index[strtolower($file->getBasename('.php'))][] = [$class, $file->getPathname()];
                }
            }
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
