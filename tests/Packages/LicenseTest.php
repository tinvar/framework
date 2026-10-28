<?php

declare(strict_types=1);

/**
 * The licence travels with each package. Every directory under packages/ is
 * published on its own -- the PHP packages split to mirrors for Packagist,
 * the npm packages packed by npm -- so each carries a copy of the MIT
 * licence, and each manifest says MIT, or a mirror would be published with
 * no licence at all.
 */

namespace Tinvar\Tests\Packages\License;

const ROOT = __DIR__ . '/../../../..';

/** @return list<string> every package directory's name */
function packages(): array
{
    $directories = glob(ROOT . '/packages/*', \GLOB_ONLYDIR) ?: [];

    return array_map(basename(...), $directories);
}

/** @return array<string, mixed> */
function manifest(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    \assert(\is_array($decoded));

    return $decoded;
}

it('knows every package there is', function () {
    expect(packages())->toEqualCanonicalizing([
        'codegen', 'database', 'devtools-extension', 'framework', 'react',
        'renderer', 'skeleton', 'support', 'tenancy', 'testing',
    ]);
});

it('ships the repository\'s MIT licence in every package', function (string $package) {
    $licence = (string) file_get_contents(ROOT . '/LICENSE');

    expect($licence)->toStartWith("MIT License\n\nCopyright (c) 2026 Carson Hopper\n")
        ->and(ROOT . "/packages/{$package}/LICENSE")->toBeFile()
        ->and((string) file_get_contents(ROOT . "/packages/{$package}/LICENSE"))->toBe($licence);
})->with(packages(...));

it('declares MIT in every manifest a package is published by', function (string $package) {
    $manifests = array_filter(
        [ROOT . "/packages/{$package}/composer.json", ROOT . "/packages/{$package}/package.json"],
        is_file(...),
    );
    // The skeleton's package.json is the new app's own, not a published
    // package's: composer.json is what Packagist reads for it.
    if ($package === 'skeleton') {
        $manifests = [ROOT . '/packages/skeleton/composer.json'];
    }

    foreach ($manifests as $path) {
        expect(manifest($path)['license'] ?? null)->toBe('MIT', "{$path} declares no MIT licence");
    }
    // The DevTools extension has no package manifest, and a Chrome manifest
    // has no licence field: its LICENSE file is the whole declaration.
    expect($manifests !== [] || $package === 'devtools-extension')->toBeTrue("packages/{$package} has no manifest");
})->with(packages(...));

it('declares MIT for the workspace itself', function () {
    expect(manifest(ROOT . '/composer.json')['license'] ?? null)->toBe('MIT')
        ->and(manifest(ROOT . '/package.json')['license'] ?? null)->toBe('MIT');
});
