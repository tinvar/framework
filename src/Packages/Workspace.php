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
 *     the check reads in one place is not loaded from another;
 *   - its require names exactly the siblings its layer may use: one it may
 *     not use is a dependency the layer rule forbids, and one it uses but
 *     does not require installs without it;
 *   - it carries no `extra.tinvar`, which marks an application's packages
 *     for the allowlist (PackagePolicy) and would take a split package for
 *     one.
 *
 * It fails closed: a manifest that cannot be read is a violation.
 */
final readonly class Workspace
{
    /** One minor version, as every package requires its siblings at. */
    private const string LOCKSTEP = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.\*$/';

    public function __construct(
        private string $root,
        private Layers $layers,
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
            $violations = [...$violations, ...$this->manifest($directory, $manifest, $where, array_keys($manifests))];

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

        return [...$violations, ...$this->workspace($root, array_keys($manifests), $lockstep === null ? null : (string) $lockstep)];
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

        if (\is_array($manifest['extra'] ?? null) && \array_key_exists('tinvar', $manifest['extra'])) {
            $violations[] = "{$where}: carries extra.tinvar, which marks an application's package for the allowlist; the framework's packages carry none.";
        }

        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(self::siblingsIn($manifest[$section] ?? [])) as $sibling) {
                if (!\in_array(substr($sibling, \strlen('tinvar/')), $packages, true)) {
                    $violations[] = "{$where}: {$section} names {$sibling}, which is no package in packages/.";
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
