<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;

/**
 * The skeleton from this repository's checkout, the way bin/skeleton-proof
 * makes one (ADR 0082), while nothing is published:
 *
 * - packages/skeleton copied into the new directory, without anything a
 *   build, an install or a run left in it;
 * - every framework package the application needs, its own and each one
 *   those require, copied into its `.packages/` and served by path
 *   repositories there -- copies, not links, so the application works
 *   wherever it is moved and holds no path into the checkout;
 * - every third-party package held to the version the workspace's
 *   composer.lock pins, so the application runs on what this checkout was
 *   tested with;
 * - @tinvar/renderer and @tinvar/react packed by npm into `.packages/` and
 *   depended on as `file:` tarballs, and the shipped package-lock.json
 *   completed with them, nothing installed and no script run: what the
 *   supply-chain check allows for a package no registry serves yet
 *   (ADR 0138), and what setup's `npm ci` installs from.
 */
final readonly class MonorepoSkeleton implements SkeletonSource
{
    /** What a copy of the skeleton or a package leaves out, at any depth. */
    public const array LEFT_OUT = ['vendor', 'node_modules', 'dist', 'dist-test', '.phpunit.cache', 'tools'];

    /** The npm packages served from the checkout as tarballs, by directory. */
    public const array NPM_PACKAGES = ['renderer', 'react'];

    public function __construct(private string $workspace) {}

    /** Whether $directory is the repository's checkout: its packages/skeleton is tinvar/skeleton. */
    public static function isWorkspace(string $directory): bool
    {
        $manifest = "{$directory}/packages/skeleton/composer.json";
        $decoded = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        return \is_array($decoded) && ($decoded['name'] ?? null) === 'tinvar/skeleton';
    }

    public function describe(): string
    {
        return "this repository's checkout, {$this->workspace}";
    }

    public function fetch(string $directory, array $extra, Processes $processes): void
    {
        self::copyTree("{$this->workspace}/packages/skeleton", $directory, self::LEFT_OUT);
        foreach (glob("{$directory}/database/*.sqlite*") ?: [] as $file) {
            unlink($file);
        }
        self::remove("{$directory}/resources/types");

        $manifest = AppFiles::readJson("{$directory}/composer.json");
        $constraint = AppFiles::constraint($directory);
        foreach ($extra as $package) {
            $manifest['require'][$package] = $constraint;
        }

        // Every framework package reached from the application's own
        // requirements, through each one's.
        $wanted = [];
        $queue = array_keys(self::siblings([...$manifest['require'] ?? [], ...$manifest['require-dev'] ?? []]));
        while ($queue !== []) {
            $package = array_shift($queue);
            if (isset($wanted[$package])) {
                continue;
            }
            $source = $this->packageDirectory($package);
            $wanted[$package] = $source;
            $queue = [...$queue, ...array_keys(self::siblings(AppFiles::readJson("{$source}/composer.json")['require'] ?? []))];
        }
        ksort($wanted);

        $manifest['repositories'] = [];
        foreach ($wanted as $package => $source) {
            $target = '.packages/' . basename($source);
            self::copyTree($source, "{$directory}/{$target}", [...self::LEFT_OUT, 'tests']);
            $manifest['repositories'][] = ['type' => 'path', 'url' => $target, 'options' => ['symlink' => false, 'versions' => [$package => '0.4.x-dev']]];
        }
        // The packages are 0.4.x-dev until a tag releases them; everything else stable.
        $manifest['minimum-stability'] = 'dev';
        $manifest['prefer-stable'] = true;
        // Every third-party package where the workspace's lock pins it, or
        // not at all.
        $lock = AppFiles::readJson("{$this->workspace}/composer.lock");
        $conflicts = [];
        foreach ([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $entry) {
            if (\is_array($entry) && \is_string($entry['name'] ?? null) && !str_starts_with($entry['name'], 'tinvar/')) {
                $conflicts[$entry['name']] = '!=' . $entry['version'];
            }
        }
        ksort($conflicts);
        $manifest['conflict'] = $conflicts;
        AppFiles::writeJson("{$directory}/composer.json", $manifest);
    }

    public function prepareNpm(string $directory, Processes $processes): void
    {
        $package = AppFiles::readJson("{$directory}/package.json");
        foreach (self::NPM_PACKAGES as $name) {
            $before = glob("{$directory}/.packages/*.tgz") ?: [];
            if ($processes->run(['npm', 'pack', '--silent', '--pack-destination', "{$directory}/.packages"], "{$this->workspace}/packages/{$name}") !== 0) {
                throw new \RuntimeException("npm pack failed in packages/{$name}: run `npm ci` in the repository first.");
            }
            $tarball = array_values(array_diff(glob("{$directory}/.packages/*.tgz") ?: [], $before))[0] ?? throw new \RuntimeException("npm pack wrote no tarball for packages/{$name}.");
            $published = AppFiles::readJson("{$this->workspace}/packages/{$name}/package.json")['name'] ?? '';
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (isset($package[$section][$published])) {
                    $package[$section][$published] = 'file:.packages/' . basename($tarball);
                }
            }
        }
        AppFiles::writeJson("{$directory}/package.json", $package);

        // The lock the skeleton ships, completed with the tarballs: every
        // other package where it already pins it, nothing installed and no
        // install script run. setup's `npm ci` installs from it.
        if ($processes->run(['npm', 'install', '--package-lock-only', '--ignore-scripts', '--no-audit', '--no-fund'], $directory) !== 0) {
            throw new \RuntimeException('npm could not lock the @tinvar tarballs into package-lock.json; see what it said above.');
        }
    }

    public function kits(Processes $processes): array
    {
        $kits = [];
        foreach (glob("{$this->workspace}/packages/*/composer.json") ?: [] as $file) {
            $declaration = KitDeclaration::of(AppFiles::readJson($file));
            if ($declaration !== null) {
                $kits[$declaration->name] = $declaration;
            }
        }
        ksort($kits);

        return $kits;
    }

    /** The checkout's directory of a framework package, by its name. */
    private function packageDirectory(string $package): string
    {
        foreach (glob("{$this->workspace}/packages/*/composer.json") ?: [] as $file) {
            if ((AppFiles::readJson($file)['name'] ?? null) === $package) {
                return \dirname($file);
            }
        }

        throw new \RuntimeException("{$package} is not a package in {$this->workspace}/packages.");
    }

    /**
     * @param array<string, string> $requires
     * @return array<string, string>
     */
    private static function siblings(array $requires): array
    {
        return array_filter($requires, static fn (string $package): bool => str_starts_with($package, 'tinvar/'), ARRAY_FILTER_USE_KEY);
    }

    /** @param list<string> $skip names left out, at any depth */
    public static function copyTree(string $from, string $to, array $skip): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0o755, true);
        }
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || \in_array($entry, $skip, true)) {
                continue;
            }
            $path = "{$from}/{$entry}";
            if (is_link($path)) {
                throw new \RuntimeException("{$path} is a link; the skeleton and the packages ship files.");
            }
            if (is_dir($path)) {
                self::copyTree($path, "{$to}/{$entry}", $skip);
            } else {
                copy($path, "{$to}/{$entry}");
                chmod("{$to}/{$entry}", fileperms($path) & 0o777);
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }
}
