<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * The layer rule of `tinvar check`: which of the framework's own parts may
 * name which, held before they are packages of their own (ADR 0054).
 *
 * Layers are listed lowest first, and each names the layers below it that
 * it may use: it uses those and itself, never one above, and never one
 * beside it that it does not name -- testing and codegen both stand on the
 * framework, and neither on the other. A layer's members are namespaces,
 * written with a trailing `\` and taking everything under them, and single
 * classes: a class sits in the layer of its most specific member, so
 * `Tinvar\Attributes\Table` can be the database's while the rest of
 * `Tinvar\Attributes\` is the framework's, with no class moved or renamed.
 *
 * A use is every name SourceReferences finds -- an import, a qualified name
 * or `::class` in code, a bare name of a class beside it in a shared
 * namespace, a class name in a string, a qualified name in a comment or
 * docblock -- so a database class cannot reach the framework by spelling
 * the name out in full, by sharing its namespace, or by putting it in a
 * docblock PHPStan reads.
 *
 * A file is in the layer of the class its path names, as PSR-4 would load
 * it, which is where a split will put it; one declaring another class is a
 * violation, so a database file cannot step out of its layer by declaring
 * itself in the framework's namespace. And the rule fails closed: a file in
 * no layer, or a source that cannot be read, is a violation, not a file
 * skipped.
 */
final readonly class Layers
{
    /** @var array<string, list<string>> name => the layers it may use besides itself */
    private array $uses;

    /**
     * @param array<string, list<string>> $layers  name => members, lowest layer first
     * @param array<string, list<string>> $uses    name => the layers below it that it may use
     * @param array<string, string>       $sources directories the check reads, relative to the root it is
     *                                             given, each with the namespace prefix PSR-4 maps it to
     * @throws \InvalidArgumentException for a layer that would use one not below it
     */
    public function __construct(
        private array $layers,
        array $uses = [],
        private array $sources = [],
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
     * The framework's layers: support, which uses nothing of Tinvar's; the
     * database, which uses only support; the framework, which uses either;
     * and on the framework, the testing layer and codegen -- already a
     * package of its own -- each over all three and neither over the other.
     *
     * Where the map is finer than a namespace, the namespace is shared: the
     * attributes the ORM reads are the database's, the container's is
     * support's, and so is Data\Lazy, the deferred value the ORM's read-only
     * scope reaches into -- the rest of Data, which maps models, is the
     * framework's.
     */
    public static function framework(): self
    {
        return new self(
            [
                'support' => [
                    'Tinvar\\Support\\',
                    'Tinvar\\Codec\\',
                    'Tinvar\\Container\\',
                    'Tinvar\\Attributes\\Dependency',
                    'Tinvar\\Data\\Lazy',
                ],
                'database' => [
                    'Tinvar\\Orm\\',
                    'Tinvar\\Attributes\\Table',
                    'Tinvar\\Attributes\\Column',
                    'Tinvar\\Attributes\\Id',
                    'Tinvar\\Attributes\\BelongsTo',
                    'Tinvar\\Attributes\\HasMany',
                    'Tinvar\\Attributes\\BeforeSave',
                    'Tinvar\\Attributes\\TenantScoped',
                ],
                'framework' => ['Tinvar\\'],
                'testing' => ['Tinvar\\Testing\\'],
                'codegen' => ['Tinvar\\Codegen\\'],
            ],
            [
                'database' => ['support'],
                'framework' => ['support', 'database'],
                'testing' => ['support', 'database', 'framework'],
                'codegen' => ['support', 'database', 'framework'],
            ],
            [
                'packages/support/src' => 'Tinvar\\',
                'packages/database/src' => 'Tinvar\\',
                'packages/framework/src' => 'Tinvar\\',
                'packages/testing/src' => 'Tinvar\\',
                'packages/codegen/src' => 'Tinvar\\Codegen\\',
            ],
        );
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
        $files = [];
        $violations = [];

        $named = [];
        foreach ($this->sources as $source => $prefix) {
            $directory = rtrim($root, '/') . '/' . $source;
            if (!is_dir($directory)) {
                $violations[] = "{$source}: not a directory, so its layers cannot be checked.";

                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    if ($contents === false) {
                        $violations[] = "{$file->getPathname()}: cannot be read, so its layer cannot be checked.";

                        continue;
                    }
                    $relative = substr($file->getPathname(), \strlen($directory) + 1);
                    $files[$source . '/' . $relative] = $contents;
                    $named[$source . '/' . $relative] = $prefix . str_replace('/', '\\', substr($relative, 0, -\strlen('.php')));
                }
            }
        }
        ksort($files, \SORT_STRING);

        [$namespaces, $classes] = self::declaredIn($files);
        foreach ($files as $path => $contents) {
            $violations = [...$violations, ...$this->checkSource($contents, $path, $namespaces, $classes, $named[$path])];
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
     * @return list<string>
     */
    public function checkSource(string $source, string $path, array $namespaces = [], array $classes = [], ?string $named = null): array
    {
        $references = SourceReferences::of($source, $namespaces, $classes);
        $self = $named ?? $references->declared ?? $references->namespace . '\\';
        $layer = $this->layerOf($self);

        if ($layer === null) {
            return ["{$path}: " . ltrim($self, '\\') . ' is in no layer; name it in Layers.'];
        }

        $violations = [];
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
