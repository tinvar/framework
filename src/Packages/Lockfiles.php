<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * The project's two lockfiles, read into one list of packages.
 *
 * Framework metadata is looked for twice for a Composer package -- in the
 * entry composer.lock recorded and in the composer.json installed under
 * vendor/ -- because either is enough for something to treat the package
 * as Tinvar's, and the check has to see whatever the strictest reader
 * would. npm's lockfile keeps no arbitrary fields, so an npm package's is
 * read from its installed package.json alone; one that is not installed
 * (an optional dependency for another platform) cannot be loaded either.
 */
final class Lockfiles
{
    public const string COMPOSER = 'composer.lock';

    public const string NPM = 'package-lock.json';

    /** The key framework metadata sits under: `extra.tinvar` and `tinvar`. */
    public const string METADATA = 'tinvar';

    public function __construct(private readonly string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    public function hasComposer(): bool
    {
        return is_file($this->root . '/composer.json');
    }

    public function hasNpm(): bool
    {
        return is_file($this->root . '/package.json');
    }

    /** @return array<mixed> */
    public function json(string $file): array
    {
        $path = $this->root . '/' . $file;
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException("{$file} is missing.");
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$file} is not valid JSON: {$e->getMessage()}.");
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException("{$file} is not a JSON object.");
        }

        return $decoded;
    }

    /** @return list<LockedPackage> */
    public function composer(): array
    {
        $lock = $this->json(self::COMPOSER);
        $manifest = $this->json('composer.json');
        $vendor = $manifest['config']['vendor-dir'] ?? 'vendor';
        $vendor = \is_string($vendor) ? $vendor : 'vendor';

        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $lock[$section] ?? [];
            if (!\is_array($entries)) {
                throw new \RuntimeException(self::COMPOSER . " has a {$section} that is not a list.");
            }
            foreach ($entries as $index => $entry) {
                if (!\is_array($entry) || !\is_string($entry['name'] ?? null)) {
                    throw new \RuntimeException(self::COMPOSER . " {$section}[{$index}] has no name.");
                }
                $name = $entry['name'];
                $dist = \is_array($entry['dist'] ?? null) ? $entry['dist'] : [];
                $source = \is_array($entry['source'] ?? null) ? $entry['source'] : [];
                $local = ($dist['type'] ?? null) === 'path';

                $directory = $this->root . '/' . $vendor . '/' . $name;
                $installed = is_dir($directory) ? $this->installedJson($directory . '/composer.json') : null;
                $from = \is_string($dist['url'] ?? null) ? $dist['url'] : (\is_string($source['url'] ?? null) ? $source['url'] : null);

                $packages[] = new LockedPackage(
                    ecosystem: Ecosystem::Composer,
                    name: $name,
                    version: \is_string($entry['version'] ?? null) ? $entry['version'] : '',
                    pin: self::composerPin($dist, $source),
                    source: $from,
                    directory: is_dir($directory) ? $directory : null,
                    metadata: self::metadataIn($entry['extra'] ?? null) ?? self::metadataIn($installed['extra'] ?? null),
                    where: self::COMPOSER . " {$name}",
                    local: $local,
                    plugin: ($entry['type'] ?? null) === 'composer-plugin',
                    alternates: array_values(array_filter(
                        [...self::composerUrls($dist), ...self::composerUrls($source)],
                        static fn (string $url): bool => $url !== $from,
                    )),
                    unpinned: $local ? [] : self::unpinnedArchives($dist),
                    downloadOptions: self::downloadOptions($entry['transport-options'] ?? null, $local),
                );
            }
        }

        return $packages;
    }

    /** @return list<LockedPackage> */
    public function npm(): array
    {
        $lock = $this->json(self::NPM);
        if (!\is_int($lock['lockfileVersion'] ?? null) || $lock['lockfileVersion'] < 2) {
            // Version 1 lockfiles record neither install scripts nor a flat
            // list of what is installed where.
            throw new \RuntimeException(self::NPM . ' must be lockfileVersion 2 or later; run npm install with npm 7 or later.');
        }
        $entries = $lock['packages'] ?? null;
        if (!\is_array($entries)) {
            throw new \RuntimeException(self::NPM . ' has no packages.');
        }

        $packages = [];
        foreach ($entries as $path => $entry) {
            $path = (string) $path;
            // The root, and the workspace directories themselves: the
            // project's own source, which git versions.
            if (!str_contains($path, 'node_modules/') || !\is_array($entry)) {
                continue;
            }
            $name = \is_string($entry['name'] ?? null) ? $entry['name'] : substr($path, strrpos($path, 'node_modules/') + \strlen('node_modules/'));
            $link = ($entry['link'] ?? false) === true;
            $directory = $this->root . '/' . $path;
            $installed = !$link && is_dir($directory) ? $this->installedJson($directory . '/package.json') : null;

            $packages[] = new LockedPackage(
                ecosystem: Ecosystem::Npm,
                name: $name,
                version: \is_string($entry['version'] ?? null) ? $entry['version'] : '',
                pin: \is_string($entry['integrity'] ?? null) ? $entry['integrity'] : null,
                source: \is_string($entry['resolved'] ?? null) ? $entry['resolved'] : null,
                directory: !$link && is_dir($directory) ? $directory : null,
                metadata: self::metadataIn($installed),
                where: self::NPM . " {$path}",
                local: $link,
                installScript: ($entry['hasInstallScript'] ?? false) === true,
            );
        }

        return $packages;
    }

    /**
     * A full commit for a git source, or the dist's own checksum when the
     * repository published one. A tag or branch name is not a pin: it can
     * be moved to other content after the lock was written.
     *
     * @param array<mixed> $dist
     * @param array<mixed> $source
     */
    private static function composerPin(array $dist, array $source): ?string
    {
        foreach ([$dist['reference'] ?? null, $source['reference'] ?? null] as $reference) {
            if (\is_string($reference) && preg_match('/^[0-9a-f]{40}$/', $reference) === 1) {
                return $reference;
            }
        }
        if (\is_string($dist['shasum'] ?? null) && preg_match('/^[0-9a-f]{40,64}$/', $dist['shasum']) === 1) {
            return $dist['shasum'];
        }

        return null;
    }

    /**
     * The archive URLs of a Composer dist whose bytes its pin does not
     * decide.
     *
     * Composer downloads a dist URL as written. It puts the dist's reference
     * into one only where the URL has a `%reference%` placeholder, or is a
     * GitHub zipball, tarball or archive URL, which it rewrites to the
     * reference (Composer\Util\Url::updateDistReference); and it checks an
     * archive's bytes only against a shasum, which GitHub-hosted packages
     * leave empty. Any other URL -- a release asset, say, on the allowed
     * github.com -- serves whatever is there when it is fetched, whatever
     * commit the entry names. A mirror's URL is taken as written too, but
     * for its placeholders.
     *
     * @param array<mixed> $dist
     * @return list<string>
     */
    private static function unpinnedArchives(array $dist): array
    {
        if (\is_string($dist['shasum'] ?? null) && preg_match('/^[0-9a-f]{40,64}$/', $dist['shasum']) === 1) {
            return [];
        }
        $reference = \is_string($dist['reference'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $dist['reference']) === 1;

        $unpinned = [];
        foreach (self::composerUrls($dist) as $index => $url) {
            $mirror = $index > 0 || !\array_key_exists('url', $dist);
            $pinned = $reference && (str_contains($url, '%reference%') || (!$mirror && preg_match(
                '{^https://(?:api\.github\.com/repos/[^/]+/[^/]+/(?:zip|tar)ball(?:/.+)?|(?:www\.)?github\.com/[^/]+/[^/]+/(?:(?:zip|tar)ball/.+|archive/.+\.(?:zip|tar)(?:\.gz)?))$}i',
                $url,
            ) === 1));
            if (!$pinned) {
                $unpinned[] = $url;
            }
        }

        return $unpinned;
    }

    /**
     * Whether a Composer lock entry's `transport-options` reach its
     * download. Composer loads them from the lock (its Locker's ArrayLoader
     * reads options) and merges them into every request for the package:
     * `ssl.verify_peer` false, a `cafile` of the lockfile's choosing, headers
     * -- for that one package, where composer.json's secure-http and
     * disable-tls say nothing. A path package's `symlink` and `relative`,
     * which say how it is linked into vendor/, download nothing.
     */
    private static function downloadOptions(mixed $options, bool $local): bool
    {
        if ($options === null || $options === []) {
            return false;
        }

        return !$local || !\is_array($options) || array_diff(array_keys($options), ['symlink', 'relative']) !== [];
    }

    /**
     * The URLs half a Composer lock entry -- its dist or its source -- names:
     * its own, and its mirrors', which Composer tries first when one is
     * preferred. A URL that is not a string is kept as its JSON, which no
     * allowed prefix matches.
     *
     * @param array<mixed> $origin
     * @return list<string>
     */
    private static function composerUrls(array $origin): array
    {
        $mirrors = $origin['mirrors'] ?? [];
        $urls = [];
        foreach ([$origin, ...(\is_array($mirrors) ? $mirrors : [['url' => $mirrors]])] as $at) {
            if (\is_array($at) && !\array_key_exists('url', $at)) {
                continue;
            }
            $url = \is_array($at) ? $at['url'] : $at;
            $urls[] = \is_string($url) ? $url : (string) json_encode($url);
        }

        return $urls;
    }

    /** @return array<mixed>|null */
    private static function metadataIn(mixed $container): ?array
    {
        if (!\is_array($container) || !\array_key_exists(self::METADATA, $container)) {
            return null;
        }

        // Present but not an object is still a claim to be a Tinvar package.
        return \is_array($container[self::METADATA]) ? $container[self::METADATA] : [];
    }

    /** @return array<mixed>|null */
    private function installedJson(string $path): ?array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
