<?php

declare(strict_types=1);

/**
 * `tinvar build --profile=...`: Vite run in the app with the profile it
 * records, where it was asked to build, and a test build refused where the
 * environment says production (ADR 0066).
 */

namespace Tinvar\Tests\Server\ProfileBuildCommand;

use Tinvar\Server\ProfileBuildCommand;

$shop = \dirname(__DIR__, 4) . '/examples/shop';

it('builds the app for production unless a profile is asked for', function () use ($shop) {
    [$command, $cwd, $env] = new ProfileBuildCommand($shop, env: ['PATH' => '/usr/bin'])->plan([]);

    expect($command)->toBe(['npx', 'vite', 'build'])
        ->and($cwd)->toBe(realpath($shop))
        ->and($env)->toBe(['PATH' => '/usr/bin', 'TINVAR_PROFILE' => 'production']);
});

it('builds for test, where --out says, from wherever it is run', function () use ($shop) {
    $root = \dirname($shop, 2);
    [, $cwd, $env] = new ProfileBuildCommand($root, env: ['TINVAR_BUILD_DIR' => '/elsewhere'])
        ->plan(['--profile=test', '--app=examples/shop', '--out=examples/shop/dist-test']);

    expect($cwd)->toBe(realpath($shop))
        ->and($env['TINVAR_PROFILE'])->toBe('test')
        ->and($env['TINVAR_BUILD_DIR'])->toBe("{$root}/examples/shop/dist-test");
});

// A shell's leftover TINVAR_BUILD_DIR does not send a production build
// somewhere the server will not look.
it('builds into the app\'s own dist unless --out says otherwise', function () use ($shop) {
    [, , $env] = new ProfileBuildCommand($shop, env: ['TINVAR_BUILD_DIR' => '/elsewhere'])->plan([]);

    expect($env)->not->toHaveKey('TINVAR_BUILD_DIR');
});

it('refuses a test build where the environment says production', function (string $marker) use ($shop) {
    $errors = [];
    $ran = false;
    $status = new ProfileBuildCommand(
        $shop,
        run: static function () use (&$ran): int {
            $ran = true;

            return 0;
        },
        err: static function (string $line) use (&$errors): void {
            $errors[] = $line;
        },
        env: [$marker => 'production'],
    )->run(['--profile=test']);

    expect($status)->toBe(1)
        ->and($ran)->toBeFalse()
        ->and($errors[0] ?? '')->toContain("{$marker}=production");
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

it('builds for staging, and refuses it where TINVAR_ENV or APP_ENV says production', function () use ($shop) {
    [, , $env] = new ProfileBuildCommand($shop, env: ['NODE_ENV' => 'production'])->plan(['--profile=staging']);
    expect($env['TINVAR_PROFILE'])->toBe('staging');

    foreach (['TINVAR_ENV', 'APP_ENV'] as $marker) {
        $errors = [];
        $status = new ProfileBuildCommand(
            $shop,
            run: static fn (): int => 0,
            err: static function (string $line) use (&$errors): void {
                $errors[] = $line;
            },
            env: [$marker => 'production'],
        )->run(['--profile=staging']);
        expect($status)->toBe(1)->and($errors[0] ?? '')->toContain("{$marker}=production");
    }
});

it('refuses what it does not know', function (string $argument) use ($shop) {
    $status = new ProfileBuildCommand($shop, run: static fn (): int => 0, err: static function (): void {}, env: [])->run([$argument]);

    expect($status)->toBe(ProfileBuildCommand::USAGE);
})->with([
    'a profile' => ['--profile=qa'],
    'an option' => ['--minify=false'],
    'a bare flag' => ['--profile'],
]);

it('refuses a directory that is not an app', function () {
    $status = new ProfileBuildCommand(sys_get_temp_dir(), run: static fn (): int => 0, err: static function (): void {}, env: [])->run([]);

    expect($status)->toBe(1);
});

// Where a person is there, Vite runs under a spinner and its output is
// given whole once it is done; in a log, it streams as it always has.
it('runs the build under a spinner where a person is there, and gives its output after', function () {
    $terminal = \Tinvar\Console\Prompts::fakeTerminal();
    $output = fopen('php://memory', 'w+');
    \assert(\is_resource($output));
    try {
        $status = ProfileBuildCommand::execute(['sh', '-c', 'echo built 12 kB; exit 3'], sys_get_temp_dir(), ['TINVAR_PROFILE' => 'staging', 'PATH' => (string) getenv('PATH')], $output);
        rewind($output);

        expect($status)->toBe(3)
            ->and(stream_get_contents($output))->toBe("built 12 kB\n")
            ->and($terminal->frames()[0])->toContain('Building for staging: sh -c')
            ->and($terminal->screen())->toBe('');

        \Tinvar\Console\Prompts::withoutInteraction();
        $streamed = tmpfile();
        \assert(\is_resource($streamed));
        expect(ProfileBuildCommand::execute(['sh', '-c', 'echo streamed'], sys_get_temp_dir(), ['PATH' => (string) getenv('PATH')], $streamed))->toBe(0);
        rewind($streamed);
        expect(stream_get_contents($streamed))->toBe("streamed\n")
            ->and(\count($terminal->frames()))->toBe(2);
    } finally {
        \Tinvar\Console\Prompts::restore();
    }
});
