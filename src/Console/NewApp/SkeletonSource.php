<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

use Tinvar\Console\Kits\KitDeclaration;

/**
 * Where `tinvar new` takes the skeleton and the framework's packages from
 * (ADR 0157): this repository's checkout, served by path repositories,
 * until tinvar/skeleton is published; Packagist once it is. detect()
 * chooses, so nothing changes for a person the day it is published.
 */
interface SkeletonSource
{
    /** What a person reads: `this repository's checkout, /src/tinvar`. */
    public function describe(): string;

    /**
     * Writes the skeleton into $directory, missing or empty, with Composer
     * told where every package the application needs comes from -- the
     * skeleton's own and each of $extra, `tinvar/tenancy` say -- but not yet
     * installed.
     *
     * @param list<string> $extra further packages the application requires
     * @throws \RuntimeException where it cannot, saying why
     */
    public function fetch(string $directory, array $extra, Processes $processes): void;

    /**
     * Makes the application's npm install able to run where the @tinvar
     * packages are not on npm yet: their tarballs inside the application,
     * and its lock completed with them (ADR 0138). Nothing where npm serves
     * them.
     *
     * @throws \RuntimeException where it cannot
     */
    public function prepareNpm(string $directory, Processes $processes): void;

    /**
     * Whether the skeleton ships accounts wired -- the accounts kit's
     * sign-up, sign-in and settings, installed and registered (ADR 0153) --
     * so `tinvar new` has nothing to add for them.
     */
    public function shipsAccounts(): bool;

    /**
     * Every kit this source can install, by its name: `accounts`.
     *
     * @return array<string, KitDeclaration>
     */
    public function kits(Processes $processes): array;
}
