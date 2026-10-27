<?php

declare(strict_types=1);

/**
 * The skeleton a new app is made from (ADR 0082), held to the template it
 * was copied from: the same pins as the framework's own CI and image, and
 * the same refusals. bin/skeleton-proof makes an app of it and runs it;
 * this is what can be read off its files without making one.
 */

namespace Tinvar\Tests\Packages\Skeleton;

const ROOT = __DIR__ . '/../../../..';

function read(string $path): string
{
    $contents = file_get_contents(ROOT . '/' . $path);
    expect($contents)->toBeString();

    return (string) $contents;
}

/** @return array<string, string> NAME => value, of every NAME=value line */
function assignments(string $file): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', read($file), $matches, \PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

it('builds with the Composer, Node and checksums the framework\'s CI pins', function () {
    $ours = assignments('packages/skeleton/docker/setup');
    $ci = assignments('.github/ci/setup');

    foreach (['COMPOSER_VERSION', 'COMPOSER_SHA256', 'NODE_VERSION'] as $name) {
        expect($ours[$name] ?? null)->toBe($ci[$name] ?? 'missing');
    }
    preg_match_all('/NODE_SHA256=([0-9a-f]{64})/', read('.github/ci/setup'), $ciNode);
    preg_match_all('/NODE_SHA256=([0-9a-f]{64})/', read('packages/skeleton/docker/setup'), $ourNode);
    expect($ourNode[1])->toBe($ciNode[1]);
});

it('builds from the bases, FrankenPHP and tini the shop\'s image pins', function () {
    $pins = static function (string $file): array {
        preg_match_all('/(?:FROM \S+@sha256:[0-9a-f]{64}|_sha256=[0-9a-f]{64}|releases\/download\/v[0-9.]+)/', read($file), $found);
        $found = array_values(array_unique($found[0]));
        sort($found);

        return $found;
    };

    $ours = $pins('packages/skeleton/Dockerfile');
    expect($ours)->not->toBe([])
        ->and(array_diff($ours, $pins('examples/shop/Dockerfile')))->toBe([]);
});

it('keeps every refusal of the shop\'s container', function () {
    $compose = read('packages/skeleton/compose.yaml');
    $dockerfile = read('packages/skeleton/Dockerfile');

    expect($compose)->toContain('read_only: true')
        ->toContain('cap_drop: [ALL]')
        ->toContain('cap_add: [SETUID, SETGID, SETPCAP]')
        ->toContain('no-new-privileges:true')
        ->toContain('TINVAR_APP_KEY: ${APP_KEY:?')
        ->toContain('TINVAR_PROBE_TOKEN: ${APP_PROBE_TOKEN:?')
        ->toContain('TINVAR_PROBE_TRUST_LOOPBACK: "0"')
        ->toContain('"127.0.0.1:8080:8080"')
        ->and($dockerfile)->toContain('TINVAR_ENV=production')
        ->toContain('TINVAR_PROBE_TRUST_LOOPBACK=0')
        ->toContain('composer install --no-dev')
        ->toContain('npm ci --ignore-scripts')
        ->toContain('ENTRYPOINT ["/usr/local/lib/tinvar/entrypoint"]')
        ->not->toContain('TINVAR_APP_KEY=');
});

it('ships no secret, and keeps secrets and state out of git and the image', function () {
    foreach (assignments('packages/skeleton/.env.example') as $name => $value) {
        expect($value)->toBe('', "{$name} has a value in .env.example");
    }

    foreach (['.gitignore', '.dockerignore'] as $ignore) {
        expect(read("packages/skeleton/{$ignore}"))->toContain('.env')->toContain('*.key')->toContain('.sqlite');
    }
    expect(glob(ROOT . '/packages/skeleton/{,*/,*/*/}*.{key,sqlite,pem}', \GLOB_BRACE) ?: [])->toBe([])
        ->and(file_exists(ROOT . '/packages/skeleton/.env'))->toBeFalse();
});

it('runs no install script and nothing it fetched when an app is made', function () {
    $composer = json_decode(read('packages/skeleton/composer.json'), true);
    \assert(\is_array($composer));

    expect($composer['scripts']['post-create-project-cmd'] ?? null)->toBe(['@php bin/tinvar setup'])
        ->and(read('packages/skeleton/bin/tinvar'))->toContain("'install', '--ignore-scripts'");
});
