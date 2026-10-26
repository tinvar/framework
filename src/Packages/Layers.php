<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * The layer rule of `tinvar check`: which of the framework's own parts may
 * name which, held before they are packages of their own (ADR 0054).
 *
 * Layers are listed lowest first, and each may use itself and the layers
 * below it, never one above. A layer's members are namespaces, written with
 * a trailing `\` and taking everything under them, and single classes: a
 * class sits in the layer of its most specific member, so
 * `Tinvar\Attributes\Table` can be the database's while the rest of
 * `Tinvar\Attributes\` is the framework's, with no class moved or renamed.
 *
 * A use is every name SourceReferences finds -- an import, a qualified name
 * or `::class` in code, a class name in a string, a qualified name in a
 * comment or docblock -- so a database class cannot reach the framework by
 * spelling the name out in full, or by putting it in a docblock PHPStan
 * reads. And it fails closed: a file under a source in no layer, or a
 * source that cannot be read, is a violation, not a file skipped.
 */
final readonly class Layers
{
    /**
     * @param array<string, list<string>> $layers  name => members, lowest layer first
     * @param list<string>                $sources directories the check reads, relative to the root it is given
     */
    public function __construct(
        private array $layers,
        private array $sources = [],
    ) {}

    /**
     * The framework's layers: support, which uses nothing of Tinvar's; the
     * database, which uses only support; the framework, which uses either;
     * and codegen, already a package of its own, over all three.
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
                'codegen' => ['Tinvar\\Codegen\\'],
            ],
            ['packages/framework/src', 'packages/codegen/src'],
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

    /** Whether a name in layer $from may use one in layer $to: itself, or one below it. */
    public function mayUse(string $from, string $to): bool
    {
        $order = array_flip(array_keys($this->layers));

        return isset($order[$from], $order[$to]) && $order[$to] <= $order[$from];
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

        foreach ($this->sources as $source) {
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
                    $files[$source . substr($file->getPathname(), \strlen($directory))] = $contents;
                }
            }
        }
        ksort($files, \SORT_STRING);

        [$namespaces, $classes] = self::declaredIn($files);
        foreach ($files as $path => $contents) {
            $violations = [...$violations, ...$this->checkSource($contents, $path, $namespaces, $classes)];
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
     * @return list<string>
     */
    public function checkSource(string $source, string $path, array $namespaces = [], array $classes = []): array
    {
        $references = SourceReferences::of($source, $namespaces, $classes);
        $self = $references->declared ?? $references->namespace . '\\';
        $layer = $this->layerOf($self);

        if ($layer === null) {
            return ["{$path}: " . ltrim($self, '\\') . ' is in no layer; name it in Layers.'];
        }

        $violations = [];
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
