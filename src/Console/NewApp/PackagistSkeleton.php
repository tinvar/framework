<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Support\PrivateDirectory;

/**
 * The skeleton as published: `composer create-project tinvar/skeleton`,
 * with nothing installed or run yet, the packages from Packagist and
 * @tinvar/react and @tinvar/renderer from npm. Until they are published,
 * Composer says it cannot find tinvar/skeleton, and `tinvar new` says to
 * run it from the repository's checkout instead.
 *
 * Composer runs from an empty directory of its own, made for the run: it
 * reads the composer.json of the directory it runs in, and loads the
 * plugins that project's vendor/ holds and allows, so run from /tmp -- or
 * from the sticky directory an app may be made under -- it would run
 * whatever another user planted there, as the developer.
 */
final readonly class PackagistSkeleton implements SkeletonSource
{
    /**
     * The framework's packages that declare a kit, each by the name its
     * extra.tinvar.kit gives it, asked of Packagist by package. Accounts are
     * none of them: the skeleton ships them wired (ADR 0153).
     */
    public const array KNOWN_KITS = ['tinvar/tenancy' => 'tenant-pages'];

    public function describe(): string
    {
        return 'Packagist (tinvar/skeleton)';
    }

    public function fetch(string $directory, array $extra, Processes $processes): void
    {
        $status = self::composer(['create-project', 'tinvar/skeleton', $directory, '--no-install', '--no-scripts', '--no-progress', '--no-interaction'], $processes);
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

    /** The published skeleton is this repository's, which ships accounts wired (ADR 0153). */
    public function shipsAccounts(): bool
    {
        return true;
    }

    public function kits(Processes $processes): array
    {
        $kits = [];
        foreach (self::KNOWN_KITS as $package => $name) {
            if (self::composer(['show', '--all', '--format=json', '--no-interaction', $package], $processes->quietly()) !== 0) {
                continue;
            }
            $kits[$name] = new KitDeclaration($package, $name, ucfirst(str_replace('-', ' ', $name)), '');
        }

        return $kits;
    }

    /**
     * Composer with $arguments, run from a new empty directory only this
     * user can enter, removed after.
     *
     * @param list<string> $arguments
     */
    private static function composer(array $arguments, Processes $processes): int
    {
        $empty = PrivateDirectory::create('tinvar-composer');
        try {
            return $processes->run(['composer', ...$arguments], $empty);
        } finally {
            PrivateDirectory::remove($empty);
        }
    }
}
