<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * Where an application's PHP is, as its composer.json tells the autoloader:
 * what `tinvar dev` watches to run codegen when a Data class, a query or an
 * action changes (ADR 0042, 0132).
 *
 * The autoload map already names every directory an application's classes
 * load from -- `App\` from app/, its factories from database/, a
 * classmap's directories and files -- so it is read rather than a directory
 * the CLI names. Only `autoload`, never `autoload-dev`: tests declare no
 * Data codegen reads.
 *
 * Kept to the application's own directory. A workspace's composer.json maps
 * every application in it (this repository's maps the shop and the docs
 * site), and one application's dev has no reason to run codegen when
 * another's PHP changes. A path that resolves outside the application, or
 * to nothing, is left out; a directory beneath another already watched is
 * left out too, since the walk covers it.
 */
final class ComposerSources
{
    /**
     * The directories and files under $app that $projectRoot's composer.json
     * autoloads from, real paths, sorted; [] where there is no composer.json,
     * it cannot be read, or nothing it names is under $app.
     *
     * @return list<string>
     */
    public static function of(string $projectRoot, string $app): array
    {
        $root = realpath($projectRoot);
        $app = realpath($app);
        if ($root === false || $app === false) {
            return [];
        }
        $json = is_file("{$root}/composer.json") ? @file_get_contents("{$root}/composer.json") : false;
        $composer = \is_string($json) ? json_decode($json, true) : null;
        $autoload = \is_array($composer) && \is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];

        $paths = [];
        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach ((array) ($autoload[$standard] ?? []) as $mapped) {
                foreach ((array) $mapped as $path) {
                    $paths[] = $path;
                }
            }
        }
        foreach (['classmap', 'files'] as $list) {
            foreach ((array) ($autoload[$list] ?? []) as $path) {
                $paths[] = $path;
            }
        }

        $found = [];
        foreach ($paths as $path) {
            if (!\is_string($path)) {
                continue;
            }
            // Relative to composer.json, as Composer reads it; '' is its directory.
            $real = realpath($path === '' ? $root : (str_starts_with($path, '/') ? $path : "{$root}/{$path}"));
            if ($real !== false && ($real === $app || str_starts_with($real, $app . \DIRECTORY_SEPARATOR))) {
                $found[$real] = true;
            }
        }

        $found = array_keys($found);
        sort($found);
        $kept = [];
        foreach ($found as $path) {
            $under = false;
            foreach ($kept as $parent) {
                if (is_dir($parent) && str_starts_with($path, $parent . \DIRECTORY_SEPARATOR)) {
                    $under = true;
                    break;
                }
            }
            if (!$under) {
                $kept[] = $path;
            }
        }

        return $kept;
    }
}
