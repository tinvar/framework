<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * The workspace rule of `tinvar check`: the framework's packages, each a
 * composer.json under packages/, released together at one version
 * (ADR 0055).
 *
 * One tag releases every package, so each requires its siblings at one
 * constraint, a single minor version (`0.4.*`): a package that drifted to
 * another would let Composer install a database from one release beside a
 * framework from the next, a pair no suite has run. Every sibling
 * constraint in every package's require and require-dev has to be that one,
 * and the workspace's own composer.json has to require each package at it,
 * flagged @dev, with the path repository serving the same minor.
 *
 * Beside the versions, what the layer rule (Layers) assumes about the
 * packages is held here, since that rule reads the sources and not the
 * manifests:
 *
 *   - a package's name is its directory's, tinvar/<directory>, and the
 *     directory is the layer of that name;
 *   - its PSR-4 map is the one Layers reads its sources by, so a namespace
 *     the check reads in one place is not loaded from another, and it
 *     autoloads nothing else but its aliases.php;
 *   - its require names exactly the siblings its layer may use: one it may
 *     not use is a dependency the layer rule forbids, and one it uses but
 *     does not require installs without it;
 *   - it carries no `extra.tinvar`, which marks an application's packages
 *     for the allowlist (PackagePolicy) and would take a split package for
 *     one;
 *   - it names no package an application opts into -- tenancy, billing -- in its
 *     require or its require-dev, so no package's own suite runs with one
 *     installed (ADR 0056).
 *
 * Beside the packages, packages/ holds applications: the skeleton a new app
 * is made from (`composer create-project tinvar/skeleton`, ADR 0082), split
 * to a mirror of its own as the packages are. An application is no layer
 * and no library, so it is held to rules of its own:
 *
 *   - it is named for its directory, and is a Composer `project`, which is
 *     what create-project copies -- and nothing else in packages/ is one;
 *   - it requires the packages at the same one constraint they require each
 *     other at, so the skeleton of a release installs that release;
 *   - no package, no other application and not the workspace requires it:
 *     an application is where a dependency tree starts, never a part of
 *     one, and installed as one it would put its code beside the
 *     framework's in someone else's vendor/.
 *
 * It fails closed: a manifest that cannot be read is a violation.
 */
final readonly class Workspace
{
    /** One minor version, as every package requires its siblings at. */
    private const string LOCKSTEP = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.\*$/';

    /**
     * @param list<string> $optIn the packages, by directory, an application
     *        opts into rather than gets with the framework: tenancy (ADR 0056)
     *        and billing (ADR 0150)
     * @param list<string> $apps the applications in packages/, by directory:
     *        the skeleton (ADR 0082)
     */
    public function __construct(
        private string $root,
        private Layers $layers,
        private array $optIn = ['tenancy', 'billing'],
        private array $apps = ['skeleton'],
    ) {}

    /**
     * Every rule a package's manifest breaks.
     *
     * @return list<string> one line per violation; empty when the packages pass
     */
    public function check(): array
    {
        $root = rtrim($this->root, '/');
        $violations = [];
        $manifests = [];
        foreach (glob("{$root}/packages/*/composer.json") ?: [] as $file) {
            $directory = basename(\dirname($file));
            $manifest = self::read($file);
            if ($manifest === null) {
                $violations[] = "packages/{$directory}/composer.json: cannot be read as a JSON object.";

                continue;
            }
            $manifests[$directory] = $manifest;
        }
        ksort($manifests, \SORT_STRING);

        if ($manifests === []) {
            return ['packages/: no package has a composer.json.'];
        }

        $constraints = [];
        foreach ($manifests as $directory => $manifest) {
            $where = "packages/{$directory}/composer.json";
            $violations = [...$violations, ...(\in_array($directory, $this->apps, true)
                ? $this->app($directory, $manifest, $where, array_keys($manifests))
                : $this->manifest($directory, $manifest, $where, array_keys($manifests)))];

            foreach (['require', 'require-dev'] as $section) {
                foreach (self::siblingsIn($manifest[$section] ?? []) as $sibling => $constraint) {
                    $constraints[$constraint][] = "{$where} {$section} {$sibling}";
                }
            }
        }

        // One constraint, a single minor; the one most packages use is the
        // workspace's, and every other is named where it is.
        uasort($constraints, static fn (array $a, array $b): int => \count($b) <=> \count($a));
        $lockstep = array_key_first($constraints);
        foreach ($constraints as $constraint => $where) {
            $constraint = (string) $constraint;
            if ($constraint !== $lockstep) {
                foreach ($where as $at) {
                    $violations[] = "{$at} is {$constraint}, where the packages require each other at {$lockstep}: one constraint, so one tag releases them together.";
                }
            }
        }
        if ($lockstep !== null && preg_match(self::LOCKSTEP, (string) $lockstep) !== 1) {
            $violations[] = "packages/: siblings are required at {$lockstep}, which is not one minor version (0.4.*).";
        }

        $packages = array_values(array_diff(array_keys($manifests), $this->apps));

        return [...$violations, ...$this->workspace($root, $packages, $lockstep === null ? null : (string) $lockstep)];
    }

    /**
     * What an application's manifest breaks: its name and type, a sibling
     * that is no package, and a sibling that is another application.
     *
     * @param array<mixed> $manifest
     * @param list<string> $packages every directory with a manifest
     * @return list<string>
     */
    private function app(string $directory, array $manifest, string $where, array $packages): array
    {
        $violations = [];
        $name = "tinvar/{$directory}";
        if (($manifest['name'] ?? null) !== $name) {
            $violations[] = "{$where}: is named " . json_encode($manifest['name'] ?? null, \JSON_UNESCAPED_SLASHES) . "; an application in packages/{$directory} is {$name}.";
        }
        if (($manifest['type'] ?? null) !== 'project') {
            $violations[] = "{$where}: is of type " . json_encode($manifest['type'] ?? null) . '; an application is a "project", which composer create-project copies.';
        }
        if (\array_key_exists("packages/{$directory}/src", $this->layers->packages())) {
            $violations[] = "{$where}: packages/{$directory}/src is a layer, and {$directory} is an application, which no layer is.";
        }

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(self::siblingsIn($manifest[$section] ?? [])) as $sibling) {
                $named = substr($sibling, \strlen('tinvar/'));
                if (!\in_array($named, $packages, true)) {
                    $violations[] = "{$where}: {$section} names {$sibling}, which is no package in packages/.";
                } elseif (\in_array($named, $this->apps, true)) {
                    $violations[] = "{$where}: {$section} names {$sibling}, an application, which nothing requires.";
                }
            }
        }

        return $violations;
    }

    /**
     * Whether a file a package autoloads is one the layer rule reads: a
     * plain relative path to a PHP file inside one of the package's PSR-4
     * directories, with no `..` or `.` to step out of it.
     */
    private function readByLayers(string $directory, mixed $file): bool
    {
        if (!\is_string($file) || preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\.php\z#', $file) !== 1) {
            return false;
        }

        $path = "packages/{$directory}/{$file}";
        foreach (array_keys($this->layers->sources()) as $source) {
            if (str_starts_with($source, "packages/{$directory}/") && str_starts_with($path, rtrim($source, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What one package's manifest breaks, besides its versions.
     *
     * @param array<mixed> $manifest
     * @param list<string> $packages every directory with a manifest
     * @return list<string>
     */
    private function manifest(string $directory, array $manifest, string $where, array $packages): array
    {
        $violations = [];
        $name = "tinvar/{$directory}";
        if (($manifest['name'] ?? null) !== $name) {
            $violations[] = "{$where}: is named " . json_encode($manifest['name'] ?? null, \JSON_UNESCAPED_SLASHES) . "; a package in packages/{$directory} is {$name}.";
        }
        if (($manifest['type'] ?? 'library') !== 'library') {
            $violations[] = "{$where}: is of type " . json_encode($manifest['type']) . '; a package is a "library", and only an application Workspace names is anything else.';
        }

        // A kit's declaration (ADR 0157) is the one thing a framework package
        // may say there: it marks nothing for the allowlist.
        if (\is_array($manifest['extra'] ?? null) && \array_key_exists('tinvar', $manifest['extra'])
            && !(\is_array($manifest['extra']['tinvar']) && Lockfiles::kitOnly($manifest['extra']['tinvar']))) {
            $violations[] = "{$where}: carries extra.tinvar, which marks an application's package for the allowlist; the framework's packages carry none but a kit's declaration.";
        }

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(self::siblingsIn($manifest[$section] ?? [])) as $sibling) {
                $named = substr($sibling, \strlen('tinvar/'));
                if (!\in_array($named, $packages, true)) {
                    $violations[] = "{$where}: {$section} names {$sibling}, which is no package in packages/.";
                } elseif (\in_array($named, $this->apps, true)) {
                    $violations[] = "{$where}: {$section} names {$sibling}, an application, which nothing requires.";
                } elseif ($named !== $directory && \in_array($named, $this->optIn, true)) {
                    // Each package's suite runs with what its manifests
                    // reach (bin/package-alone), and the framework's is how
                    // the framework is shown to work without tenancy: a
                    // require-dev naming it would install it there.
                    $violations[] = "{$where}: {$section} names {$sibling}, which an application opts into; no other package may install it, so each suite runs without it.";
                }
            }
        }

        $layer = $this->layers->packages()["packages/{$directory}/src"] ?? null;
        if ($layer !== $directory) {
            return [...$violations, "{$where}: packages/{$directory}/src is " . ($layer === null ? 'no layer' : "the {$layer} layer") . "; the layer rule has to read it as {$directory}."];
        }

        // Its require, against what its layer may use.
        $required = array_map(static fn (string $sibling): string => substr($sibling, \strlen('tinvar/')), array_keys(self::siblingsIn($manifest['require'] ?? [])));
        foreach ($this->layers->names() as $other) {
            if ($other === $directory) {
                continue;
            }
            $may = $this->layers->mayUse($directory, $other);
            $requires = \in_array($other, $required, true);
            if ($requires && !$may) {
                $violations[] = "{$where}: requires tinvar/{$other}, which the {$directory} layer may not use.";
            } elseif ($may && !$requires) {
                $violations[] = "{$where}: does not require tinvar/{$other}, which the {$directory} layer uses; without it the package installs without what it names.";
            }
        }

        // Nothing autoloaded but that map and the aliases (ADR 0055), which
        // name the old namespaces on purpose and so sit beside src/: a
        // classmap, PSR-0 or another file would load code no layer holds.
        // A file of functions inside a directory the layer rule reads is
        // read with it -- the query builder's fetch() and the framework's
        // scroll() (ADR 0057) -- so it is held to its package's layer.
        $autoload = \is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
        $only = 'which the layer rule does not read; a package autoloads its PSR-4 map, files inside it, and, beside src/, aliases.php alone.';
        foreach ($autoload as $kind => $entries) {
            if ($kind === 'files') {
                foreach (\is_array($entries) ? $entries : [$entries] as $file) {
                    if ($file !== 'aliases.php' && !$this->readByLayers($directory, $file)) {
                        $violations[] = "{$where}: autoloads the file " . (\is_string($file) ? $file : json_encode($file)) . ", {$only}";
                    }
                }
            } elseif ($kind !== 'psr-4') {
                $violations[] = "{$where}: autoloads by {$kind}, {$only}";
            }
        }

        // Its PSR-4 map, against the sources the layer rule reads.
        $mapped = [];
        $psr4 = \is_array($manifest['autoload']['psr-4'] ?? null) ? $manifest['autoload']['psr-4'] : [];
        foreach ($psr4 as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $mapped['packages/' . $directory . '/' . rtrim((string) $path, '/')] = (string) $prefix;
            }
        }
        $read = array_filter(
            $this->layers->sources(),
            static fn (string $source): bool => str_starts_with($source, "packages/{$directory}/"),
            \ARRAY_FILTER_USE_KEY,
        );
        ksort($mapped);
        ksort($read);
        if ($mapped !== $read) {
            $violations[] = "{$where}: autoloads " . self::describe($mapped) . ', and the layer rule reads ' . self::describe($read) . '; they have to be the same directories and prefixes.';
        }

        return $violations;
    }

    /**
     * What the workspace's own composer.json breaks: each package required
     * at the lockstep constraint, @dev, from a path repository serving the
     * same minor.
     *
     * @param list<string> $packages
     * @return list<string>
     */
    private function workspace(string $root, array $packages, ?string $lockstep): array
    {
        $workspace = self::read("{$root}/composer.json");
        if ($workspace === null) {
            return ['composer.json: cannot be read as a JSON object.'];
        }
        if ($lockstep === null) {
            return [];
        }

        $versions = [];
        foreach (\is_array($workspace['repositories'] ?? null) ? $workspace['repositories'] : [] as $repository) {
            if (\is_array($repository) && ($repository['type'] ?? null) === 'path' && \is_array($repository['options']['versions'] ?? null)) {
                $versions = [...$versions, ...$repository['options']['versions']];
            }
        }

        $violations = [];
        $required = [...(\is_array($workspace['require'] ?? null) ? $workspace['require'] : []), ...(\is_array($workspace['require-dev'] ?? null) ? $workspace['require-dev'] : [])];
        $serving = substr($lockstep, 0, -\strlen('*')) . 'x-dev';
        foreach ($this->apps as $app) {
            if (\array_key_exists("tinvar/{$app}", $required)) {
                $violations[] = "composer.json: requires tinvar/{$app}, an application, which nothing requires.";
            }
        }
        foreach ($packages as $directory) {
            $name = "tinvar/{$directory}";
            if (($required[$name] ?? null) !== "{$lockstep}@dev") {
                $violations[] = "composer.json: requires {$name} at " . json_encode($required[$name] ?? null, \JSON_UNESCAPED_SLASHES) . ", where the workspace requires each package at {$lockstep}@dev.";
            }
            if (($versions[$name] ?? null) !== $serving) {
                $violations[] = "composer.json: the path repository serves {$name} as " . json_encode($versions[$name] ?? null, \JSON_UNESCAPED_SLASHES) . ", where the packages require {$lockstep}; it serves {$serving}.";
            }
        }

        return $violations;
    }

    /**
     * @param mixed $requires
     * @return array<string, string> the Tinvar packages named, with their constraints
     */
    private static function siblingsIn(mixed $requires): array
    {
        $siblings = [];
        foreach (\is_array($requires) ? $requires : [] as $package => $constraint) {
            if (str_starts_with((string) $package, 'tinvar/')) {
                $siblings[(string) $package] = \is_string($constraint) ? $constraint : (string) json_encode($constraint);
            }
        }

        return $siblings;
    }

    /** @return array<mixed>|null */
    private static function read(string $file): ?array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;
        $decoded = \is_string($contents) ? json_decode($contents, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, string> $map */
    private static function describe(array $map): string
    {
        if ($map === []) {
            return 'nothing';
        }

        return implode(', ', array_map(static fn (string $directory, string $prefix): string => "{$prefix} from {$directory}", array_keys($map), $map));
    }
}
