<?php

declare(strict_types=1);

/**
 * Each package's tests load another package's fixtures only through the
 * path Composer installed that package at (ADR 0115): never by a path out
 * of their own package, which a split mirror's checkout does not have.
 */

namespace Tinvar\Tests\Packages\PackageFixtures;

use Composer\InstalledVersions;

const ROOT = __DIR__ . '/../../../..';

/**
 * Every require and include in $package's tests whose path is written from
 * __DIR__, resolved, that lands in another package's directory.
 *
 * @return list<string>
 */
function crossings(string $root, string $package): array
{
    $packages = realpath("{$root}/packages");
    $own = realpath("{$root}/packages/{$package}");
    \assert($packages !== false && $own !== false);
    $found = [];

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$own}/tests", \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        preg_match_all("/\\b(?:require|include)(?:_once)?\\s*\\(?\\s*(?:__DIR__|dirname\\(__DIR__(?:,\\s*(\\d+))?\\))\\s*\\.\\s*'([^']+)'/", $source, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $from = \dirname($file->getPathname(), 1 + ($match[1][1] === -1 ? 0 : (int) ($match[1][0] ?: 1)));
            $target = normalize($from . '/' . ltrim($match[2][0], '/'));
            if (str_starts_with($target, $packages . '/') && !str_starts_with($target, $own . '/')) {
                $line = substr_count($source, "\n", 0, $match[0][1]) + 1;
                $found[] = substr($file->getPathname(), \strlen($own) + 1) . ":{$line}: " . substr($target, \strlen($packages) + 1);
            }
        }
    }
    sort($found);

    return $found;
}

/** $path with its `.` and `..` worked out, whether or not it exists. */
function normalize(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }

    return '/' . implode('/', $parts);
}

it('loads no other package\'s file by a path out of its own tests', function (string $package) {
    expect(crossings(ROOT, $package))->toBe([]);
})->with(['support', 'database', 'framework', 'tenancy', 'testing', 'codegen']);

it('finds a path out of a package however it is written', function () {
    $root = sys_get_temp_dir() . '/tinvar-fixtures-' . bin2hex(random_bytes(4));
    mkdir("{$root}/packages/tenancy/tests/Deep", 0o777, true);
    mkdir("{$root}/packages/database/tests", 0o777, true);
    // Written with LOAD and INCLUDE for the keywords, so this file is not
    // itself a crossing the first test finds.
    file_put_contents("{$root}/packages/tenancy/tests/Deep/ExampleTest.php", strtr(<<<'PHP'
        <?php
        LOAD_once __DIR__ . '/../../../database/tests/models.php';
        LOAD __DIR__ . '/../Own.php';
        INCLUDE_once dirname(__DIR__, 2) . '/../database/tests/schema.php';
        LOAD_once(__DIR__ . '/../../../tenancy/tests/Own.php');
        LOAD_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/models.php';
        PHP, ['LOAD' => 'require', 'INCLUDE' => 'include']));

    try {
        expect(crossings($root, 'tenancy'))->toBe([
            'tests/Deep/ExampleTest.php:2: database/tests/models.php',
            'tests/Deep/ExampleTest.php:4: database/tests/schema.php',
        ]);
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

it('reaches the fixtures it shares through the package that ships them', function () {
    // The database's and the framework's tests ship in their dists -- no
    // export-ignore keeps them out -- so a mirror's vendor/ has them too.
    foreach (['database', 'framework'] as $package) {
        $attributes = ROOT . "/packages/{$package}/.gitattributes";
        expect(is_file($attributes) ? (string) file_get_contents($attributes) : '')->not->toMatch('/tests\S*\s+export-ignore/');
    }

    expect(realpath((string) InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php'))
        ->toBe(realpath(ROOT . '/packages/database/tests/Orm/Fixtures/models.php'));
});
