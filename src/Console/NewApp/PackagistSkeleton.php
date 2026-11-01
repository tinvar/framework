<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;

/**
 * The skeleton as published: `composer create-project tinvar/skeleton`,
 * with nothing installed or run yet, the packages from Packagist and
 * @tinvar/react and @tinvar/renderer from npm. Until they are published,
 * Composer says it cannot find tinvar/skeleton, and `tinvar new` says to
 * run it from the repository's checkout instead.
 */
final readonly class PackagistSkeleton implements SkeletonSource
{
    /** The packages a kit may be published as, asked of Packagist by name. */
    public const array KNOWN_KITS = ['tinvar/accounts'];

    public function describe(): string
    {
        return 'Packagist (tinvar/skeleton)';
    }

    public function fetch(string $directory, array $extra, Processes $processes): void
    {
        $status = $processes->run(['composer', 'create-project', 'tinvar/skeleton', $directory, '--no-install', '--no-scripts', '--no-progress', '--no-interaction'], \dirname($directory));
        if ($status !== 0) {
            throw new \RuntimeException('composer create-project tinvar/skeleton failed. Until tinvar/skeleton is on Packagist, run `bin/tinvar new` from the framework\'s repository, which makes the app from its checkout (--from=monorepo).');
        }
        if ($extra !== []) {
            AppFiles::requirePackages($directory, array_fill_keys($extra, AppFiles::constraint($directory)));
        }
    }

    public function prepareNpm(string $directory, Processes $processes): void
    {
        // npm serves @tinvar/* once it is published; until then setup says
        // how to install them from their tarballs (ADR 0138).
    }

    public function kits(Processes $processes): array
    {
        $kits = [];
        foreach (self::KNOWN_KITS as $package) {
            $output = '';
            $probe = new Processes(static function (string $text) use (&$output): void {
                $output .= $text;
            });
            if ($probe->run(['composer', 'show', '--all', '--format=json', '--no-interaction', $package], sys_get_temp_dir()) !== 0) {
                continue;
            }
            $name = substr($package, (int) strrpos($package, '/') + 1);
            $kits[$name] = new KitDeclaration($package, $name, ucfirst($name), '');
        }

        return $kits;
    }
}
