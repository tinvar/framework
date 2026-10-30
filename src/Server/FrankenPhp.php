<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Config\ConfigException;
use Tinvar\Config\FrameworkConfig;

/**
 * Where the FrankenPHP binary is.
 *
 * In the order a person would expect to override it: the --frankenphp flag,
 * then TINVAR_FRANKENPHP, then the project's tools/frankenphp -- and, from a
 * git worktree, the main checkout's, since a git-ignored binary is in the
 * checkout it was put in and nowhere else -- then `frankenphp` on the PATH.
 */
final class FrankenPhp
{
    /** @return string the binary's path */
    public static function locate(string $projectRoot, ?string $explicit = null): string
    {
        foreach (self::candidates($projectRoot, $explicit) as $source => $path) {
            if ($path === null || $path === '') {
                continue;
            }
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
            // A path someone named that is not there is a mistake to report,
            // not a reason to quietly run a different binary.
            if ($source === 'flag' || $source === 'env') {
                throw new ServeException(
                    ($source === 'flag' ? '--frankenphp' : 'TINVAR_FRANKENPHP') . " names {$path}, which is not an executable file.",
                );
            }
        }

        throw new ServeException(
            'No FrankenPHP binary found. Put one at tools/frankenphp, set TINVAR_FRANKENPHP, pass '
            . '--frankenphp=PATH, or install it on your PATH (https://frankenphp.dev).',
        );
    }

    /** @return array<string, string|null> */
    private static function candidates(string $projectRoot, ?string $explicit): array
    {
        // As the framework's config reads TINVAR_FRANKENPHP: a refusal of
        // any of its variables is said here, before anything starts.
        try {
            $env = FrameworkConfig::load()->frankenphp;
        } catch (ConfigException $e) {
            throw new ServeException($e->getMessage(), 0, $e);
        }

        return [
            'flag' => $explicit,
            'env' => $env,
            'project' => "{$projectRoot}/tools/frankenphp",
            'main checkout' => self::mainCheckout($projectRoot),
            'path' => self::onPath(),
        ];
    }

    /**
     * The main checkout's tools/frankenphp, when this is a linked worktree:
     * its `.git` is a file naming a directory under the main checkout's.
     */
    private static function mainCheckout(string $projectRoot): ?string
    {
        $marker = "{$projectRoot}/.git";
        if (!is_file($marker)) {
            return null;
        }

        $line = (string) file_get_contents($marker);
        if (preg_match('/^gitdir:\s*(.+?)\s*$/m', $line, $m) !== 1) {
            return null;
        }

        // <main>/.git/worktrees/<name>
        $gitDir = str_starts_with($m[1], '/') ? $m[1] : "{$projectRoot}/{$m[1]}";
        $common = \dirname($gitDir, 2);

        return basename($common) === '.git' ? \dirname($common) . '/tools/frankenphp' : null;
    }

    private static function onPath(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file("{$directory}/frankenphp") && is_executable("{$directory}/frankenphp")) {
                return "{$directory}/frankenphp";
            }
        }

        return null;
    }
}
