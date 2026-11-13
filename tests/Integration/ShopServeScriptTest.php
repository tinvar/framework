<?php

declare(strict_types=1);

/**
 * The example apps' own dev entry points on a developer's machine whose
 * shell says nothing (ADR 0213): the shop's bin/serve, its `npm run dev`
 * and the docs' bin/docs dev say TINVAR_ENV=local unless the shell says
 * otherwise -- and a shell that says production is still refused.
 *
 * Each is asked to listen on 0.0.0.0, which dev mode refuses after the
 * environment check and before anything starts: the refusal it reaches
 * says which check it got past.
 */

namespace Tinvar\Tests\Integration\ShopServeScript;

use Tinvar\Bench\Shop;
use Tinvar\Support\PrivateDirectory;

/**
 * Runs $command with $env alone beside PATH and HOME, and returns its exit
 * status and what it wrote.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 * @return array{int, string}
 */
function run(array $command, array $env): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        ...$env,
    ]);
    \assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** The directories each test made, removed after it. */
final class Made
{
    /** @var list<string> */
    public static array $directories = [];
}

afterEach(function () {
    foreach (Made::$directories as $directory) {
        PrivateDirectory::remove($directory);
    }
    Made::$directories = [];
});

/**
 * A database of the shop's own for one run, and a probe token so nothing is
 * written beside the app key.
 *
 * @return array<string, string>
 */
function shopEnvironment(): array
{
    $directory = PrivateDirectory::create('serve-script');
    Made::$directories[] = $directory;
    touch("{$directory}/shop.sqlite");

    return [
        'SHOP_DATABASE' => "{$directory}/shop.sqlite",
        'SHOP_STORAGE' => "{$directory}/uploads",
        'TINVAR_PROBE_TOKEN' => str_repeat('p', 40),
        'APP_KEY' => 'shop-serve-script-key-of-32-bytes!!',
    ];
}

it('has bin/serve --dev say TINVAR_ENV=local where the shell says nothing', function () {
    [$status, $output] = run([Shop::root() . '/bin/serve', '--dev', '--host=0.0.0.0'], shopEnvironment());

    expect($output)->not->toContain('TINVAR_ENV is not')
        ->and($output)->toContain('listens on loopback only')
        ->and($status)->not->toBe(0);
});

it('has bin/serve --dev still refused where the shell says production', function () {
    [$status, $output] = run([Shop::root() . '/bin/serve', '--dev', '--host=0.0.0.0'], [...shopEnvironment(), 'TINVAR_ENV' => 'production']);

    expect($output)->toContain('tinvar serve --dev is refused: TINVAR_ENV=production')
        ->and($status)->not->toBe(0);
});

it('has the docs\' bin/docs dev say TINVAR_ENV=local where the shell says nothing', function () {
    [$status, $output] = run(['php', \dirname(Shop::root()) . '/docs/bin/docs', 'dev', '--host=0.0.0.0'], []);

    expect($output)->not->toContain('TINVAR_ENV is not')
        ->and($output)->toContain('listens on loopback only')
        ->and($status)->not->toBe(0);
});

it('has the docs\' bin/docs dev still refused where the shell says production', function () {
    [$status, $output] = run(['php', \dirname(Shop::root()) . '/docs/bin/docs', 'dev', '--host=0.0.0.0'], ['TINVAR_ENV' => 'production']);

    expect($output)->toContain('refused: TINVAR_ENV=production')
        ->and($status)->not->toBe(0);
});

it('has the shop\'s npm run dev say TINVAR_ENV=local unless the shell says otherwise', function () {
    $package = json_decode((string) file_get_contents(Shop::root() . '/package.json'), true, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($package) && \is_array($package['scripts']));

    expect($package['scripts']['dev'])->toBe('TINVAR_ENV=${TINVAR_ENV:-local} vite');
});
