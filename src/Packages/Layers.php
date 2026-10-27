<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * The layer rule of `tinvar check`: which of the framework's packages may
 * name which (ADR 0054), each layer now a package of its own (ADR 0055).
 *
 * Layers are listed lowest first, and each names the layers below it that
 * it may use: it uses those and itself, never one above, and never one
 * beside it that it does not name -- tenancy, testing and codegen all stand
 * on the framework, and none on another. A layer's members are namespaces,
 * written with a trailing `\` and taking everything under them, and single
 * classes: a class sits in the layer of its most specific member. The
 * framework's map is namespaces alone, since the classes it once shared out
 * one by one were renamed into their packages' namespaces.
 *
 * A use is every name SourceReferences finds -- an import, a qualified name
 * or `::class` in code, a bare name of a class beside it in a shared
 * namespace, a class name in a string, a qualified name in a comment or
 * docblock -- so a database class cannot reach the framework by spelling
 * the name out in full, by sharing its namespace, or by putting it in a
 * docblock PHPStan reads.
 *
 * A file is in the layer of the package whose directory holds it, and the
 * class its path names, as the package's PSR-4 map loads it, has to be in
 * that layer too: a class of the ORM's namespace in the framework's
 * directory would ship in the wrong package. One declaring another class
 * than its path names is a violation, so a database file cannot step out
 * of its layer by declaring itself in the framework's namespace. And the
 * rule fails closed: a file in no layer, a file in a package's directory
 * that none of its PSR-4 directories holds, a link, and a source that
 * cannot be read are violations, not files skipped.
 */
final readonly class Layers
{
    /** @var array<string, list<string>> name => the layers it may use besides itself */
    private array $uses;

    /**
     * @param array<string, list<string>> $layers  name => members, lowest layer first
     * @param array<string, list<string>> $uses    name => the layers below it that it may use
     * @param array<string, string>       $sources  directories the check reads, relative to the root it is
     *                                              given, each with the namespace prefix PSR-4 maps it to
     * @param array<string, string>       $packages directories each holding one package's sources, relative
     *                                              to the root, with the layer the package is; with none,
     *                                              a file's layer is its class's
     * @throws \InvalidArgumentException for a layer that would use one not below it
     */
    public function __construct(
        private array $layers,
        array $uses = [],
        private array $sources = [],
        private array $packages = [],
    ) {
        $below = [];
        $allowed = [];
        foreach (array_keys($layers) as $layer) {
            $allowed[$layer] = $uses[$layer] ?? [];
            foreach ($allowed[$layer] as $used) {
                if (!isset($below[$used])) {
                    throw new \InvalidArgumentException("Layer '{$layer}' may use only layers listed before it, and '{$used}' is not one.");
                }
            }
            $below[$layer] = true;
        }

        $unknown = array_diff(array_keys($uses), array_keys($layers));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('No such layer: ' . implode(', ', $unknown) . '.');
        }

        $this->uses = $allowed;
    }

    /**
     * The framework's layers, each a package: support, which uses nothing of
     * Tinvar's; the database, which uses only support; the framework, which
     * uses either; and on the framework, tenancy, the testing layer and
     * codegen, each over all three and none over another (ADR 0056): the
     * framework names no tenancy, and tenancy's tests, not the testing
     * layer, are where tenants are tested.
     *
     * The sources are each package's PSR-4 directories, as its composer.json
     * maps them -- tinvar check holds the two to each other (Workspace) --
     * and each package's src/ is read whole, so a file none of them holds is
     * found rather than skipped.
     */
    public static function framework(): self
    {
        $map = self::maps()['packages'];

        return new self($map['layers'], $map['uses'], $map['sources'], $map['packages']);
    }

    /**
     * The modules inside the framework's package (ADR 0077), read over its
     * src/ alone, each file in the module of its class: the queue's storage,
     * on the database alone; the core, which names no module; jobs, on the
     * queue and the core; events, mail, webhooks and transfers, each on jobs
     * and none on another; and the kernel -- the HTTP kernel, the console and
     * the server -- which wires them together. The packages around the
     * framework are layers here too, as they are in framework(), so a name
     * of theirs is in theirs rather than the core's.
     */
    public static function modules(): self
    {
        $map = self::maps()['modules'];

        return new self($map['layers'], $map['uses'], $map['sources']);
    }

    /**
     * The two maps, which live beside src/ (layers.php): they name every
     * layer's namespace, as a string, on purpose.
     *
     * @return array{
     *     packages: array{layers: array<string, list<string>>, uses: array<string, list<string>>, sources: array<string, string>, packages: array<string, string>},
     *     modules: array{layers: array<string, list<string>>, uses: array<string, list<string>>, sources: array<string, string>},
     * }
     */
    private static function maps(): array
    {
        /** @var array{packages: array{layers: array<string, list<string>>, uses: array<string, list<string>>, sources: array<string, string>, packages: array<string, string>}, modules: array{layers: array<string, list<string>>, uses: array<string, list<string>>, sources: array<string, string>}} */
        return require \dirname(__DIR__, 2) . '/layers.php';
    }

    /**
     * The directories the check reads, each with its PSR-4 prefix.
     *
     * @return array<string, string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Each package's directory, with the layer it is.
     *
     * @return array<string, string>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /** The layer $name is in, by its most specific member; null for a name in none. */
    public function layerOf(string $name): ?string
    {
        $name = strtolower(ltrim($name, '\\'));
        $layer = null;
        $length = -1;

        foreach ($this->layers as $candidate => $members) {
            foreach ($members as $member) {
                $member = strtolower($member);
                $matches = str_ends_with($member, '\\')
                    ? str_starts_with($name . '\\', $member)
                    : $name === $member;

                if ($matches && \strlen($member) > $length) {
                    $layer = $candidate;
                    $length = \strlen($member);
                }
            }
        }

        return $layer;
    }

    /** Whether a name in layer $from may use one in layer $to: its own, or one it names. */
    public function mayUse(string $from, string $to): bool
    {
        return isset($this->uses[$from], $this->uses[$to])
            && ($from === $to || \in_array($to, $this->uses[$from], true));
    }

    /**
     * Every layer crossed in the sources, under $root.
     *
     * @return list<string> one line per violation; empty when the tree passes
     */
    public function check(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];
        $named = [];
        $packageOf = [];
        $violations = [];

        // Each package's directory read whole, or each source when the map
        // has no packages: a file is named by the PSR-4 source holding it.
        $sources = $this->sources;
        uksort($sources, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $roots = $this->packages !== [] ? $this->packages : array_fill_keys(array_keys($this->sources), null);
        foreach ($sources as $source => $prefix) {
            if ($this->packages !== [] && self::within($source, array_keys($this->packages)) === null) {
                $violations[] = "{$source}: a source in none of the packages, so its layer cannot be checked.";
            }
        }

        foreach ($roots as $directory => $package) {
            if (!is_dir("{$root}/{$directory}")) {
                $violations[] = "{$directory}: not a directory, so its layers cannot be checked.";

                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$directory}", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                $path = substr($file->getPathname(), \strlen($root) + 1);
                // Composer autoloads through a link, and the iterator does
                // not walk into one to a directory: what is behind it would
                // load and never be read here. Refused, wherever it points.
                if ($file->isLink()) {
                    $violations[] = "{$path}: a link, so what it points at is not read as the package's own; its layer cannot be checked.";

                    continue;
                }
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = self::within($path, array_keys($sources));
                if ($source === null) {
                    $violations[] = "{$path}: in the {$package} package, and in none of its PSR-4 directories, so its layer cannot be checked.";

                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    $violations[] = "{$file->getPathname()}: cannot be read, so its layer cannot be checked.";

                    continue;
                }
                $files[$path] = $contents;
                $named[$path] = $sources[$source] . str_replace('/', '\\', substr($path, \strlen($source) + 1, -\strlen('.php')));
                $packageOf[$path] = $package;
            }
        }
        ksort($files, \SORT_STRING);

        [$namespaces, $classes] = self::declaredIn($files);
        foreach ($files as $path => $contents) {
            $violations = [...$violations, ...$this->checkSource($contents, $path, $namespaces, $classes, $named[$path], $packageOf[$path])];
        }

        return $violations;
    }

    /**
     * Every layer one file crosses.
     *
     * @param list<string> $namespaces every namespace there is, for the names
     *        comments shorten (SourceReferences)
     * @param list<string> $classes    every class there is, for the names a
     *        file's own namespace leaves unqualified
     * @param string|null  $named      the class the file's path names, which
     *        puts it in its layer; without one, the class it declares does
     * @param string|null  $package    the layer of the package whose directory
     *        holds the file, which is then the file's layer, and its class's
     *        has to be the same
     * @return list<string>
     */
    public function checkSource(string $source, string $path, array $namespaces = [], array $classes = [], ?string $named = null, ?string $package = null): array
    {
        $references = SourceReferences::of($source, $namespaces, $classes);
        $self = $named ?? $references->declared ?? $references->namespace . '\\';
        $layer = $this->layerOf($self);

        if ($layer === null) {
            return ["{$path}: " . ltrim($self, '\\') . ' is in no layer; name it in Layers.'];
        }

        $violations = [];
        if ($package !== null && $package !== $layer) {
            $violations[] = "{$path}: " . ltrim($self, '\\') . " is in the {$layer} layer, and its file is in the {$package} package";
            $layer = $package;
        }
        if ($named !== null && $references->declared !== null && strcasecmp($named, $references->declared) !== 0) {
            $violations[] = "{$path}: declares {$references->declared}, where its path names {$named}";
        }

        foreach ($references->references as $reference) {
            $used = $this->layerOf($reference['name']);
            if ($used !== null && !$this->mayUse($layer, $used)) {
                $violations[] = "{$path}:{$reference['line']}: {$layer} uses {$used}, {$reference['name']} ({$reference['kind']})";
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * The layers, lowest first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->layers);
    }

    /**
     * The longest of $directories that $path is, or is in; null for none.
     *
     * @param list<string> $directories
     */
    private static function within(string $path, array $directories): ?string
    {
        $found = null;
        foreach ($directories as $directory) {
            if (($path === rtrim($directory, '/') || str_starts_with($path, rtrim($directory, '/') . '/')) && ($found === null || \strlen($directory) > \strlen($found))) {
                $found = $directory;
            }
        }

        return $found;
    }

    /**
     * Each namespace the files declare, with every namespace above one, and
     * each class.
     *
     * @param array<string, string> $files
     * @return array{list<string>, list<string>}
     */
    private static function declaredIn(array $files): array
    {
        $namespaces = [];
        $classes = [];
        foreach ($files as $contents) {
            $file = SourceReferences::of($contents);
            if ($file->declared !== null) {
                $classes[] = $file->declared;
            }

            $parts = explode('\\', $file->namespace);
            for ($n = 1; $file->namespace !== '' && $n <= \count($parts); $n++) {
                $namespaces[implode('\\', \array_slice($parts, 0, $n))] = true;
            }
        }

        return [array_map('strval', array_keys($namespaces)), $classes];
    }
}
