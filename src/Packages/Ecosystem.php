<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * Where a package comes from, and so which lockfile pins it: composer.lock
 * for PHP, package-lock.json for JavaScript. A name alone does not say --
 * `acme/billing` could be either -- so the allowlist names both.
 */
enum Ecosystem: string
{
    case Composer = 'composer';
    case Npm = 'npm';
}
