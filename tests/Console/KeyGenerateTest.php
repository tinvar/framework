<?php

declare(strict_types=1);

/**
 * `key:generate` and `doctor` as every bin/tinvar has them (ADR 0156):
 * secrets generated into their files outside the project, never printed,
 * and never on a server.
 */

namespace Tinvar\Tests\Console\KeyGenerate;

use Tinvar\Console\AppConsole;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Http\FileBuildStore;
use Tinvar\Packages\PackagePolicy;

/** @return array{AppConsole, string} the console, and the state directory its secrets go in */
function console(): array
{
    $state = sys_get_temp_dir() . '/tinvar-keys-' . bin2hex(random_bytes(4));

    return [new AppConsole(
        root: __DIR__ . '/../../../../examples/shop',
        console: __FILE__,
        namespace: 'Shop',
        classes: static fn (): DiscoveredClasses => new DiscoveredClasses(),
        packages: static fn (): PackagePolicy => new PackagePolicy(),
        buildStore: static fn (): FileBuildStore => new FileBuildStore(sys_get_temp_dir() . '/unused-builds.json'),
        database: static fn (): string => ':memory:',
        migrations: sys_get_temp_dir(),
        projectRoot: __DIR__ . '/../../../..',
        keyFile: static fn (): string => "{$state}/app.key",
        probeTokenFile: static fn (): string => "{$state}/app.probe-token",
    ), $state];
}

/**
 * @param array<string, string|false> $env set for the run, put back after
 * @return array{int, string}
 */
function ran(AppConsole $console, array $env, string ...$argv): array
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv($value === false ? $name : "{$name}={$value}");
    }
    ob_start();
    try {
        $status = $console->run([__FILE__, ...array_values($argv)]);
    } finally {
        $out = (string) ob_get_clean();
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    return [$status, $out];
}

it('generates the key and the token once, private, saying where and never what', function () {
    [$console, $state] = console();
    $unset = ['TINVAR_APP_KEY' => false, 'TINVAR_PROBE_TOKEN' => false];

    [$status, $out] = ran($console, $unset, 'key:generate');
    $key = (string) file_get_contents("{$state}/app.key");

    expect($status)->toBe(0)
        ->and($out)->toBe("the app key: generated {$state}/app.key\nthe probe token: generated {$state}/app.probe-token\n")
        ->and(fileperms("{$state}/app.key") & 0o777)->toBe(0o600)
        ->and($out)->not->toContain(trim($key));

    [, $again] = ran($console, $unset, 'key:generate');
    expect($again)->toContain('(already there, kept)')
        ->and(file_get_contents("{$state}/app.key"))->toBe($key);
    exec('rm -rf ' . escapeshellarg($state));
});

it('writes nothing on a server, and prints a fresh key for its secret store with --show', function () {
    [$console, $state] = console();

    expect(ran($console, ['TINVAR_ENV' => 'production', 'TINVAR_APP_KEY' => false], 'key:generate')[0])->toBe(1)
        ->and(is_dir($state))->toBeFalse();
    [$status, $out] = ran($console, [], 'key:generate', '--show');
    expect($status)->toBe(0)
        ->and(\strlen(base64_decode(trim($out), true) ?: ''))->toBe(32)
        ->and(is_dir($state))->toBeFalse();
});

it('runs the doctor over the application and exits 1 on a problem', function () {
    [$console] = console();

    [$status, $out] = ran($console, ['TINVAR_ENV' => 'production', 'TINVAR_APP_KEY' => false, 'TINVAR_PROBE_TOKEN' => false], 'doctor');

    expect($status)->toBe(1)
        ->and($out)->toContain("The app key and the probe token\n  problem  The app key is not set, and TINVAR_ENV=production")
        ->and($out)->toContain('Production refusals');
});
