<?php

declare(strict_types=1);

/**
 * `tinvar serve` up to the point it would start anything: the arguments, the
 * app, the binary. Mistakes are reported with exit status 64 and nothing to
 * clean up. Serving for real is ServeIntegrationTest.
 */

namespace Tinvar\Tests\Server\ServeCommand;

use Tinvar\Server\FrankenPhp;
use Tinvar\Server\PortFinder;
use Tinvar\Server\ServeCommand;
use Tinvar\Server\ServeException;

/** A scratch directory, removed when the test process ends. */
function scratch(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-serve-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        // Not in a forked child, which runs shutdown functions too.
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return $directory;
}

/** An app directory: a Caddyfile and, unless told otherwise, a build. */
function app(bool $built = true): string
{
    $app = scratch();
    file_put_contents("{$app}/Caddyfile", "# test\n");
    if ($built) {
        mkdir("{$app}/dist/rsc", 0700, true);
        file_put_contents("{$app}/dist/rsc/index.js", "export {};\n");
    }

    return $app;
}

/** A project root with a FrankenPHP where the command looks, and the renderer. */
function project(): string
{
    $root = scratch();
    mkdir("{$root}/tools", 0700);
    file_put_contents("{$root}/tools/frankenphp", "#!/bin/sh\n");
    chmod("{$root}/tools/frankenphp", 0700);
    mkdir("{$root}/packages/renderer/bin", 0700, true);
    file_put_contents("{$root}/packages/renderer/bin/renderer.js", '');
    file_put_contents("{$root}/packages/renderer/bin/dev-renderer.js", '');

    return $root;
}

/** @return array{ServeCommand, \ArrayObject<int, string>} the command, and the lines it writes to standard error */
function command(string $root, ?string $cwd = null): array
{
    $errors = new \ArrayObject();
    $command = new ServeCommand(
        $root,
        new PortFinder(static fn (): bool => true),
        static function (): void {},
        static function (string $line) use ($errors): void {
            $errors[] = $line;
        },
        $cwd,
    );

    return [$command, $errors];
}

/** Runs with TINVAR_FRANKENPHP unset, so the project's binary is the one found. */
function withoutEnvironmentBinary(\Closure $body): mixed
{
    $saved = getenv('TINVAR_FRANKENPHP');
    putenv('TINVAR_FRANKENPHP');
    try {
        return $body();
    } finally {
        putenv($saved === false ? 'TINVAR_FRANKENPHP' : "TINVAR_FRANKENPHP={$saved}");
    }
}

it('plans to serve the app it is given, on 127.0.0.1 and a port it chose', function () {
    withoutEnvironmentBinary(function () {
        $root = project();
        $app = app();
        [$command] = command($root);

        $plan = $command->plan(["--app={$app}", '--workers=3', '--renderers=2']);

        expect($plan['host'])->toBe('127.0.0.1')
            ->and($plan['port'])->toBeGreaterThanOrEqual(6000)->toBeLessThanOrEqual(7000)
            ->and($plan['app'])->toBe(realpath($app))
            ->and($plan['entry'])->toBe(realpath($app) . '/dist/rsc/index.js')
            ->and($plan['frankenphp'])->toBe("{$root}/tools/frankenphp")
            ->and($plan['workers'])->toBe(3)
            ->and($plan['renderers'])->toBe(2);
    });
});

// ADR 0028: a document render's CPU is nearly all the renderer's, so the
// cores go to renderers -- half of them, where the measured gains flattened
// -- and never more renderers than workers to keep them busy.
it('runs half as many renderers as cores, and never more than there are workers', function () {
    expect(ServeCommand::defaultRenderers(10, 10))->toBe(5)
        ->and(ServeCommand::defaultRenderers(16, 16))->toBe(8)
        ->and(ServeCommand::defaultRenderers(10, 2))->toBe(2)
        ->and(ServeCommand::defaultRenderers(1, 1))->toBe(1)
        ->and(ServeCommand::defaultRenderers(3, 3))->toBe(1);
});

it('defaults its renderers from the cores and the workers it plans', function () {
    withoutEnvironmentBinary(function () {
        [$command] = command(project());

        expect($command->plan(['--app=' . app(), '--workers=1'])['renderers'])->toBe(1);
    });
});

// The queue worker and the scheduler, beside the server, through the console
// serve is run from.
it('plans a queue worker and a scheduler for --worker and --schedule, from its console', function () {
    withoutEnvironmentBinary(function () {
        $root = project();
        $app = app();
        $console = scratch() . '/console';
        file_put_contents($console, "<?php\n");
        $command = new ServeCommand($root, new PortFinder(static fn (): bool => true), static function (): void {}, static function (): void {}, console: $console);

        expect($command->plan(["--app={$app}"])['background'])->toBe([])
            ->and($command->plan(["--app={$app}", '--worker'])['background'])->toBe(['queue:work'])
            ->and($command->plan(["--app={$app}", '--worker', '--schedule'])['background'])->toBe(['queue:work', 'schedule:work']);
    });
});

it('refuses --worker with no console to run it through, and a flag given a value', function () {
    withoutEnvironmentBinary(function () {
        [$command, $errors] = command(project());

        expect($command->run(['--app=' . app(), '--worker']))->toBe(ServeCommand::USAGE)
            ->and($command->run(['--app=' . app(), '--worker=2']))->toBe(ServeCommand::USAGE)
            ->and($errors[0])->toContain('given none')
            ->and($errors[1])->toContain("Unknown argument '--worker=2'");
    });
});

// The app is found where the command runs, not built into the framework.
it('serves the app in the working directory when none is named', function () {
    withoutEnvironmentBinary(function () {
        $app = app();
        [$command] = command(project(), $app);

        expect($command->plan([])['app'])->toBe(realpath($app));
    });
});

it('refuses, with a usage status, what it cannot serve', function (\Closure $arguments, string $message) {
    withoutEnvironmentBinary(function () use ($arguments, $message) {
        $root = project();
        [$command, $errors] = command($root, scratch());

        expect($command->run($arguments()))->toBe(ServeCommand::USAGE)
            ->and(implode("\n", $errors->getArrayCopy()))->toContain($message);
    });
})->with([
    'an unknown argument' => [fn (): array => ['--verbose'], "Unknown argument '--verbose'"],
    'no app here' => [fn (): array => [], 'No app to serve here'],
    'an app without a Caddyfile' => [fn (): array => ['--app=' . scratch()], 'has no Caddyfile'],
    'an app not built' => [fn (): array => ['--app=' . app(built: false)], 'is not built'],
    'a bad host' => [fn (): array => ['--app=' . app(), '--host=not a host'], '--host must be an IP address'],
    'a bad port' => [fn (): array => ['--app=' . app(), '--port=70000'], '--port must be a whole number'],
    'a bad worker count' => [fn (): array => ['--app=' . app(), '--workers=0'], '--workers must be a whole number'],
    'no renderers' => [fn (): array => ['--app=' . app(), '--renderers=0'], '--renderers must be a whole number from 1 to 64'],
    'too many renderers' => [fn (): array => ['--app=' . app(), '--renderers=65'], '--renderers must be a whole number from 1 to 64'],
    'a missing binary' => [fn (): array => ['--app=' . app(), '--frankenphp=/no/such/frankenphp'], 'not an executable file'],
]);

it('refuses a port in use before starting anything', function () {
    withoutEnvironmentBinary(function () {
        $errors = [];
        $command = new ServeCommand(
            project(),
            new PortFinder(static fn (): bool => false),
            static function (): void {},
            static function (string $line) use (&$errors): void {
                $errors[] = $line;
            },
        );

        expect($command->run(['--app=' . app(), '--port=6100']))->toBe(ServeCommand::USAGE)
            ->and($errors)->toBe([
                'Port 6100 on 127.0.0.1 is in use or not available to this user. Stop whatever holds it, choose '
                . 'another --port, or leave --port off to pick a free one.',
            ]);
    });
});

describe('--dev', function () {
    /** An app as dev mode serves it: its source and a front controller, and no build. */
    function devApp(): string
    {
        $app = app(built: false);
        mkdir("{$app}/public", 0700);
        file_put_contents("{$app}/public/index.php", "<?php\n");

        return $app;
    }

    /** Runs $body with these variables set, then puts them back. */
    function withEnvironment(array $variables, \Closure $body): mixed
    {
        $saved = [];
        foreach ($variables as $name => $value) {
            $saved[$name] = getenv($name);
            putenv("{$name}={$value}");
        }
        try {
            return $body();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    it('plans Vite\'s dev server as the renderer, with no build, behind the framework\'s own Caddyfile', function () {
        withoutEnvironmentBinary(function () {
            $root = project();
            $app = devApp();
            [$command] = command($root);

            $plan = $command->plan(['--dev', "--app={$app}"]);

            expect($plan['dev'])->toBeTrue()
                ->and($plan['entry'])->toBeNull()
                ->and($plan['renderer'])->toBe("{$root}/packages/renderer/bin/dev-renderer.js")
                ->and($plan['caddyfile'])->toEndWith('/packages/framework/resources/Caddyfile.dev')
                ->and($plan['caddyfile'])->toBeFile()
                ->and($plan['vitePort'])->toBeInt()->not->toBe($plan['port'])
                ->and($plan['host'])->toBe('127.0.0.1');
        });
    });

    it('needs no Caddyfile of the app\'s, since every app is served the one way in dev', function () {
        withoutEnvironmentBinary(function () {
            $app = devApp();
            unlink("{$app}/Caddyfile");
            [$command] = command(project());

            expect($command->plan(['--dev', "--app={$app}"])['app'])->toBe(realpath($app));
        });
    });

    // Loudly, before anything is looked at or started.
    it('is refused wherever the environment says production', function (string $marker) {
        withoutEnvironmentBinary(function () use ($marker) {
            [$command, $errors] = command(project());

            $status = withEnvironment([$marker => 'production'], fn () => $command->run(['--dev', '--app=' . devApp()]));

            expect($status)->toBe(ServeCommand::USAGE)
                ->and(implode("\n", $errors->getArrayCopy()))->toContain("tinvar serve --dev is refused: {$marker}=production");
        });
    })->with(['NODE_ENV', 'TINVAR_ENV', 'APP_ENV']);

    it('listens on loopback only', function (string $host) {
        withoutEnvironmentBinary(function () use ($host) {
            [$command, $errors] = command(project());

            expect($command->run(['--dev', '--app=' . devApp(), "--host={$host}"]))->toBe(ServeCommand::USAGE)
                ->and(implode("\n", $errors->getArrayCopy()))->toContain('listens on loopback only');
        });
    })->with(['0.0.0.0', '192.168.1.20', '::']);

    it('needs the front controller it runs for every request', function () {
        withoutEnvironmentBinary(function () {
            [$command, $errors] = command(project());

            expect($command->run(['--dev', '--app=' . app(built: false)]))->toBe(ServeCommand::USAGE)
                ->and(implode("\n", $errors->getArrayCopy()))->toContain('has no public/index.php');
        });
    });
});

describe('where the FrankenPHP binary is', function () {
    it('takes --frankenphp first, then TINVAR_FRANKENPHP, then the project', function () {
        $root = project();
        $other = scratch() . '/frankenphp';
        file_put_contents($other, '');
        chmod($other, 0700);

        withoutEnvironmentBinary(function () use ($root, $other) {
            expect(FrankenPhp::locate($root))->toBe("{$root}/tools/frankenphp")
                ->and(FrankenPhp::locate($root, $other))->toBe($other);

            putenv("TINVAR_FRANKENPHP={$other}");
            expect(FrankenPhp::locate($root))->toBe($other);
        });
    });

    // A binary is git-ignored, so it is in the checkout it was put in: a
    // worktree finds the main checkout's.
    it('finds the main checkout\'s from a git worktree', function () {
        $main = project();
        mkdir("{$main}/.git/worktrees/feature", 0700, true);
        $worktree = scratch();
        file_put_contents("{$worktree}/.git", "gitdir: {$main}/.git/worktrees/feature\n");

        withoutEnvironmentBinary(function () use ($main, $worktree) {
            expect(FrankenPhp::locate($worktree))->toBe("{$main}/tools/frankenphp");
        });
    });

    it('says how to get one when there is none', function () {
        $saved = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            withoutEnvironmentBinary(function () {
                expect(fn () => FrankenPhp::locate(scratch()))->toThrow(ServeException::class, 'No FrankenPHP binary found');
            });
        } finally {
            putenv("PATH={$saved}");
        }
    });

    it('refuses a TINVAR_FRANKENPHP that names nothing rather than run another', function () {
        withoutEnvironmentBinary(function () {
            putenv('TINVAR_FRANKENPHP=/no/such/frankenphp');
            expect(fn () => FrankenPhp::locate(project()))->toThrow(ServeException::class, 'TINVAR_FRANKENPHP names /no/such/frankenphp');
        });
    });
});
