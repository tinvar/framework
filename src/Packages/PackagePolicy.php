<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * What an application lets into its dependency tree, in typed config.
 *
 * "Packages are enabled only from an explicit allowlist in typed config,
 * with a lockfile. An undeclared package with framework metadata fails the
 * build" (tinvar-spec.md). Nothing is discovered: a package that says it is
 * for Tinvar is refused until it is named here, and one named here is
 * refused until tinvar.lock pins what was reviewed (see SupplyChain).
 *
 * The rest is the same idea applied to the whole tree, where the lockfiles
 * already pin every package by hash: which hosts a package may come from,
 * and which npm packages may run a script when they are installed. Each of
 * those is a short list on purpose -- a new entry is a decision someone
 * should see in review, not something an install did on its own.
 */
final readonly class PackagePolicy
{
    /** npm's own registry: where a lock entry comes from unless told otherwise. */
    public const string NPM_REGISTRY = 'https://registry.npmjs.org/';

    /** Where Packagist's GitHub-hosted packages download from. */
    public const string GITHUB_ARCHIVES = 'https://api.github.com/repos/';

    /** Where the same packages' git sources are cloned from. */
    public const string GITHUB_REPOSITORIES = 'https://github.com/';

    /**
     * @param list<AllowedPackage> $packages the packages with framework metadata the app enables
     * @param array<string, string> $installScripts npm package => why it may run an install script
     * @param list<string> $registries URL prefixes npm packages may resolve from
     * @param list<string> $composerSources URL prefixes Composer packages may download from: every dist, source and mirror
     */
    public function __construct(
        public array $packages = [],
        public array $installScripts = [],
        public array $registries = [self::NPM_REGISTRY],
        public array $composerSources = [self::GITHUB_ARCHIVES, self::GITHUB_REPOSITORIES],
    ) {
        $seen = [];
        foreach ($packages as $package) {
            if (isset($seen[$package->key()])) {
                throw new \InvalidArgumentException("{$package->name} is allowed twice.");
            }
            $seen[$package->key()] = true;
        }

        foreach ($installScripts as $name => $reason) {
            if (trim($reason) === '') {
                throw new \InvalidArgumentException(
                    "Say why {$name} may run an install script: the reason is what a reviewer reads.",
                );
            }
        }

        // A prefix ending anywhere but a slash lets a lookalike host or path
        // through: `https://registry.npmjs.org` also prefixes
        // `https://registry.npmjs.org.example`.
        foreach ([...$registries, ...$composerSources] as $prefix) {
            if (!str_starts_with($prefix, 'https://') || !str_ends_with($prefix, '/')) {
                throw new \InvalidArgumentException(
                    "'{$prefix}' is not an https URL prefix ending in a slash.",
                );
            }
        }
    }

    public function allows(Ecosystem $ecosystem, string $name): bool
    {
        foreach ($this->packages as $package) {
            if ($package->ecosystem === $ecosystem && $package->name === $name) {
                return true;
            }
        }

        return false;
    }

    public function registryAllows(string $url): bool
    {
        return self::prefixed($url, $this->registries);
    }

    public function composerSourceAllows(string $url): bool
    {
        return self::prefixed($url, $this->composerSources);
    }

    /** @param list<string> $prefixes */
    private static function prefixed(string $url, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
