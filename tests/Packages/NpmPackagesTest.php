<?php

declare(strict_types=1);

/**
 * The npm packages are published to the registry at the version one tag
 * releases every package at (ADR 0055, 0174): each is public, at the
 * release the Composer packages' sibling constraint names, and packs its
 * code, its types and its licence -- never its tests or its tsconfig.
 */

namespace Tinvar\Tests\Packages\NpmPackages;

use Symfony\Component\Process\Process;

const ROOT = __DIR__ . '/../../../..';

/** The packages npm publishes, by directory under packages/. */
const PUBLISHED = ['mail' => '@tinvar/mail', 'react' => '@tinvar/react', 'renderer' => '@tinvar/renderer'];

/** @return array<string, mixed> */
function manifest(string $path): array
{
    $decoded = json_decode((string) file_get_contents(ROOT . "/{$path}"), true, 512, \JSON_THROW_ON_ERROR);
    \assert(\is_array($decoded));

    return $decoded;
}

/** The release the Composer packages require each other at: `0.4` from `0.4.*`. */
function minor(): string
{
    $require = manifest('packages/framework/composer.json')['require'] ?? [];
    \assert(\is_array($require) && \is_string($require['tinvar/support'] ?? null));
    expect($require['tinvar/support'])->toMatch('/^\d+\.\d+\.\*$/');

    return substr($require['tinvar/support'], 0, -2);
}

it('publishes each package public, at the Composer packages\' release', function (string $directory) {
    $manifest = manifest("packages/{$directory}/package.json");

    expect($manifest['name'] ?? null)->toBe(PUBLISHED[$directory])
        ->and($manifest)->not->toHaveKey('private')
        ->and($manifest['version'] ?? null)->toMatch('/^' . preg_quote(minor(), '/') . '\.\d+$/')
        ->and($manifest['publishConfig'] ?? null)->toBe(['access' => 'public']);
})->with(array_keys(PUBLISHED));

it('releases the three at one version', function () {
    $versions = array_map(static fn (string $directory): mixed => manifest("packages/{$directory}/package.json")['version'] ?? null, array_keys(PUBLISHED));

    expect(array_unique($versions))->toHaveCount(1);
});

it('is the release the skeleton asks npm for', function (string $directory) {
    $dependencies = manifest('packages/skeleton/package.json')['dependencies'] ?? [];
    \assert(\is_array($dependencies));

    expect($dependencies[PUBLISHED[$directory]] ?? null)->toBe(minor() . '.x');
})->with(array_keys(PUBLISHED));

it('packs its code, types and licence, and never its tests', function (string $directory) {
    $process = new Process(['npm', 'pack', '--dry-run', '--json', '--ignore-scripts', '-w', PUBLISHED[$directory]], ROOT);
    $process->mustRun();
    $packed = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
    \assert(\is_array($packed) && \is_array($packed[0]['files'] ?? null));
    $paths = array_column($packed[0]['files'], 'path');

    expect($paths)->toContain('LICENSE', 'package.json')
        ->and(array_filter($paths, static fn (string $path): bool => !preg_match('#^(src/|bin/|LICENSE$|package\.json$|README\.md$)#', $path)))->toBe([]);
})->with(array_keys(PUBLISHED));

// npm rewrites a bin path that starts with ./ as it publishes, and warns:
// the manifest says what the registry will serve.
it('names each bin as npm publishes it', function (string $directory) {
    $bin = manifest("packages/{$directory}/package.json")['bin'] ?? [];
    expect($bin)->toBeArray();

    foreach ($bin as $name => $path) {
        expect($path)->not->toStartWith('./', "{$name} names {$path}")
            ->and(ROOT . "/packages/{$directory}/{$path}")->toBeFile();
    }
})->with(array_keys(PUBLISHED));
