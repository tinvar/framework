<?php

declare(strict_types=1);

/**
 * `tinvar dev`: refused in production and off loopback before anything
 * starts; serve --dev run with the toolbar's run named to it; codegen at the
 * start and again whenever the watched PHP settles after a change; and
 * everything stopped together. With --tunnel, a quick tunnel opened first
 * and its name handed to serve, and closed with it (ADR 0190).
 */

namespace Tinvar\Tests\Server\DevCommand;

use Tinvar\Server\ComposerSources;
use Tinvar\Server\DevCommand;
use Tinvar\Server\ServeException;
use Tinvar\Server\SourceWatcher;

const RUNNER = __DIR__ . '/Fixtures/dev-runner.php';

/** An app directory with a front controller, as dev asks for; removed after the test. */
function app(): string
{
    $app = sys_get_temp_dir() . '/tinvar-dev-app-' . bin2hex(random_bytes(4));
    mkdir("{$app}/public", 0755, true);
    file_put_contents("{$app}/public/index.php", '<?php');
    register_shutdown_function(static function () use ($app): void {
        exec('rm -rf ' . escapeshellarg($app));
    });

    return (string) realpath($app);
}

function command(?string $cwd = null): DevCommand
{
    return new DevCommand(
        console: '/bin/tinvar',
        watch: [],
        codegen: static fn (string $app): array => ['php', '/bin/tinvar', 'codegen', "--out={$app}/resources/types"],
        err: static function (): void {},
        workingDirectory: $cwd,
    );
}

/** @param array<string, string> $env */
function withEnv(array $env, \Closure $run): mixed
{
    $before = [];
    foreach ($env as $name => $value) {
        $before[$name] = getenv($name);
        putenv("{$name}={$value}");
    }
    try {
        return $run();
    } finally {
        foreach ($before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

// --------------------------------------------------------------- planning

it('runs serve --dev on the app, with what it was given, and codegen into the app\'s types', function () {
    $app = app();
    $plan = command()->plan(["--app={$app}", '--port=6123', '--worker', '--dev']);

    expect($plan['app'])->toBe($app)
        ->and($plan['serve'])->toBe(['serve', '--dev', "--app={$app}", '--port=6123', '--worker'])
        ->and($plan['codegen'])->toBe(['php', '/bin/tinvar', 'codegen', "--out={$app}/resources/types"]);
});

it('serves the working directory when no app is named', function () {
    $app = app();

    expect(command($app)->plan([])['serve'])->toBe(['serve', '--dev', "--app={$app}"])
        ->and(command(\dirname($app))->plan(['--app=' . basename($app)])['app'])->toBe($app);
});

it('refuses wherever the environment says production, before anything else', function (string $marker) {
    $app = app();

    withEnv([$marker => 'production'], function () use ($app, $marker): void {
        expect(fn () => command()->plan(["--app={$app}"]))
            ->toThrow(ServeException::class, "tinvar dev is refused: {$marker}=production")
            // Even when nothing else about the invocation would pass.
            ->and(fn () => command()->plan(['--app=/no/such/app', '--host=0.0.0.0']))
            ->toThrow(ServeException::class, 'is refused');
    });
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

it('listens on loopback only', function (string $host) {
    expect(fn () => command()->plan(['--app=' . app(), "--host={$host}"]))
        ->toThrow(ServeException::class, "loopback only (127.0.0.1 or ::1); refusing {$host}");
})->with(['0.0.0.0', '192.168.1.20', 'localhost', '::']);

it('refuses a directory that is no app', function () {
    $empty = sys_get_temp_dir() . '/tinvar-dev-empty-' . bin2hex(random_bytes(4));
    mkdir($empty);
    try {
        expect(fn () => command()->plan(["--app={$empty}"]))->toThrow(ServeException::class, 'has no public/index.php')
            ->and(fn () => command()->plan(['--app=/no/such/app']))->toThrow(ServeException::class, 'No app at /no/such/app');
    } finally {
        rmdir($empty);
    }
});

it('answers a bad invocation with the usage status, starting nothing', function () {
    $said = [];
    $status = new DevCommand('/bin/tinvar', err: static function (string $line) use (&$said): void {
        $said[] = $line;
    })->run(['--app=' . app(), '--host=0.0.0.0']);

    expect($status)->toBe(DevCommand::USAGE)->and($said[0])->toContain('loopback only');
});

// ----------------------------------------------------------------- tunnels

const CLOUDFLARED = __DIR__ . '/Fixtures/fake-cloudflared';

/**
 * The fake cloudflared, told where to log by the script itself: dev hands
 * cloudflared none of its own environment, DEV_TEST_LOG included.
 */
function cloudflared(string $log): string
{
    file_put_contents("{$log}.bin", "#!/bin/sh\nDEV_TEST_LOG=" . escapeshellarg($log) . ' exec ' . escapeshellarg(CLOUDFLARED) . " \"\$@\"\n");
    chmod("{$log}.bin", 0o755);

    return "{$log}.bin";
}

it('looks for cloudflared under --tunnel before anything starts, and refuses it beside --public-host', function () {
    $app = app();

    withEnv(['TINVAR_CLOUDFLARED' => CLOUDFLARED], function () use ($app): void {
        $plan = command()->plan(["--app={$app}", '--tunnel']);
        expect($plan['cloudflared'])->toBe(CLOUDFLARED)
            // Serve is told the port and the name once the tunnel has one.
            ->and($plan['serve'])->toBe(['serve', '--dev', "--app={$app}"])
            ->and(command()->plan(["--app={$app}"])['cloudflared'])->toBeNull()
            ->and(fn () => command()->plan(["--app={$app}", '--tunnel', '--public-host=dev.example.com']))
            ->toThrow(ServeException::class, 'Pass one of them');
    });

    withEnv(['TINVAR_CLOUDFLARED' => '', 'PATH' => '/nonexistent'], function () use ($app): void {
        expect(fn () => command()->plan(["--app={$app}", '--tunnel']))
            ->toThrow(ServeException::class, '--tunnel runs cloudflared, which is not on the PATH');
    });
    withEnv(['TINVAR_CLOUDFLARED' => 'cloudflared'], function () use ($app): void {
        expect(fn () => command()->plan(["--app={$app}", '--tunnel']))
            ->toThrow(ServeException::class, 'not an executable file at an absolute path');
    });
});

// A --public-host names a tunnel the developer runs; serve holds it to being one.
it('hands --public-host on to serve as given', function () {
    $app = app();

    expect(command()->plan(["--app={$app}", '--public-host=dev.example.com'])['serve'])
        ->toBe(['serve', '--dev', "--app={$app}", '--public-host=dev.example.com']);
});

it('opens a quick tunnel to the port serve listens on, tells serve its name, and closes it with serve', function () {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not available.');
    }
    $app = app();
    $log = sys_get_temp_dir() . '/tinvar-dev-log-' . bin2hex(random_bytes(4));
    [$process, $stdout, $log] = started($app, ['--tunnel', '--port=6124'], ['TINVAR_CLOUDFLARED' => cloudflared($log), 'TINVAR_APP_KEY' => 'secret-key', 'DB_PASSWORD' => 'secret'], $log);

    try {
        expect(waitFor(static fn (): bool => is_file("{$log}.serve"), 20))->toBeTrue('serve started: ' . @file_get_contents("{$log}.err"));
        $serve = json_decode((string) file_get_contents("{$log}.serve"), true);
        \assert(\is_array($serve));
        expect($serve['arguments'])->toBe(['--dev', "--app={$app}", '--port=6124', '--public-host=grade-habits-seller-arrange.trycloudflare.com'])
            ->and(trim((string) file_get_contents("{$log}.cloudflared")))->toBe('tunnel --no-autoupdate --grace-period 1s --url http://127.0.0.1:6124')
            // Nothing of dev's environment but what a process needs to run.
            ->and(trim((string) file_get_contents("{$log}.cloudflared.env")))->toBe('')
            ->and((string) file_get_contents("{$log}.err"))->toContain('[tunnel] grade-habits-seller-arrange.trycloudflare.com reaches port 6124');

        posix_kill(proc_get_status($process)['pid'], SIGTERM);

        expect(stopped($process))->toBe(0)
            ->and(is_file("{$log}.stopped"))->toBeTrue('serve was stopped')
            ->and(waitFor(static fn (): bool => is_file("{$log}.cloudflared.stopped")))->toBeTrue('cloudflared was stopped');
    } finally {
        if (\is_resource($process) && proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        cleanUp($log);
    }
});

it('stops everything, and says why, when the tunnel closes', function () {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not available.');
    }
    $log = sys_get_temp_dir() . '/tinvar-dev-log-' . bin2hex(random_bytes(4));
    [$process, $stdout, $log] = started(app(), ['--tunnel'], ['TINVAR_CLOUDFLARED' => cloudflared($log)], $log);

    try {
        expect(waitFor(static fn (): bool => is_file("{$log}.serve"), 20))->toBeTrue();
        touch("{$log}.tunnel-down");

        expect(stopped($process))->toBe(1)
            ->and((string) file_get_contents("{$log}.err"))->toContain('cloudflared exited, so the tunnel is closed; stopping.')
            ->and((string) file_get_contents("{$log}.err"))->toContain('[cloudflared]   2026-11-08T17:00:09Z ERR connection to the edge lost')
            ->and(is_file("{$log}.stopped"))->toBeTrue('serve was stopped');
    } finally {
        cleanUp($log);
    }
});

it('starts no serve when cloudflared names no tunnel, and says what it said instead', function () {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not available.');
    }
    $log = sys_get_temp_dir() . '/tinvar-dev-log-' . bin2hex(random_bytes(4));
    touch("{$log}.nameless");
    // It exits before it names one: dev is not left waiting the full timeout.
    touch("{$log}.tunnel-down");
    [$process, $stdout, $log] = started(app(), ['--tunnel'], ['TINVAR_CLOUDFLARED' => cloudflared($log)], $log);

    try {
        expect(stopped($process))->toBe(1)
            ->and((string) file_get_contents("{$log}.err"))->toContain('cloudflared gave the tunnel no trycloudflare.com name')
            ->and((string) file_get_contents("{$log}.err"))->toContain('Requesting new quick Tunnel')
            // The API it asked, named in its error, is no tunnel's name.
            ->and((string) file_get_contents("{$log}.err"))->not->toContain('[tunnel] api.trycloudflare.com')
            ->and(is_file("{$log}.serve"))->toBeFalse();
    } finally {
        cleanUp($log);
    }
});

// ---------------------------------------------------------------- watching

it('reports a change once the writes have settled: written, added and removed', function () {
    $dir = app();
    mkdir("{$dir}/app/Data", 0755, true);
    file_put_contents("{$dir}/app/Data/Product.php", '<?php final class Product {}');
    file_put_contents("{$dir}/app/notes.txt", 'not PHP');
    $watcher = new SourceWatcher(["{$dir}/app"]);

    expect($watcher->changes())->toBe([]);

    // Same size, same second: told apart by what it holds.
    file_put_contents("{$dir}/app/Data/Product.php", '<?php final class Produce {}');
    expect($watcher->changes())->toBe([], 'not while the writes may still be arriving')
        ->and($watcher->changes())->toBe(["{$dir}/app/Data/Product.php"])
        ->and($watcher->changes())->toBe([]);

    file_put_contents("{$dir}/app/Data/Order.php", '<?php');
    unlink("{$dir}/app/Data/Product.php");
    file_put_contents("{$dir}/app/notes.txt", 'still not PHP');
    $watcher->changes();
    expect($watcher->changes())->toBe(["{$dir}/app/Data/Order.php", "{$dir}/app/Data/Product.php"]);
});

it('waits while a file keeps changing, and reports it once', function () {
    $dir = app();
    mkdir("{$dir}/app");
    $watcher = new SourceWatcher(["{$dir}/app"]);

    for ($i = 0; $i < 3; $i++) {
        file_put_contents("{$dir}/app/Query.php", "<?php // save {$i}");
        expect($watcher->changes())->toBe([]);
    }
    expect($watcher->changes())->toBe(["{$dir}/app/Query.php"]);
});

it('watches a single file, and walks past vendor, node_modules and dot-directories', function () {
    $dir = app();
    foreach (['vendor/acme', 'node_modules/x', '.cache', 'app'] as $sub) {
        mkdir("{$dir}/{$sub}", 0755, true);
    }
    file_put_contents("{$dir}/helpers.php", '<?php');
    $watcher = new SourceWatcher([$dir, "{$dir}/helpers.php"]);

    foreach (['vendor/acme/A.php', 'node_modules/x/b.php', '.cache/c.php'] as $ignored) {
        file_put_contents("{$dir}/{$ignored}", '<?php');
    }
    $watcher->changes();
    expect($watcher->changes())->toBe([]);

    file_put_contents("{$dir}/helpers.php", '<?php // changed');
    file_put_contents("{$dir}/app/Page.php", '<?php');
    $watcher->changes();
    expect($watcher->changes())->toBe(["{$dir}/app/Page.php", "{$dir}/helpers.php"]);
});

it('finds what composer.json autoloads under the app, and nothing outside it', function () {
    $root = app();
    foreach (['shop/app', 'shop/database/factories', 'shop/lib', 'docs/app', 'outside'] as $sub) {
        mkdir("{$root}/{$sub}", 0755, true);
    }
    file_put_contents("{$root}/shop/helpers.php", '<?php');
    file_put_contents("{$root}/composer.json", json_encode(['autoload' => [
        'psr-4' => [
            'Shop\\' => 'shop/app/',
            'Shop\\Database\\' => 'shop/database/',
            'Shop\\Database\\Factories\\' => 'shop/database/factories/',
            'Docs\\' => 'docs/app/',
            'Twice\\' => ['shop/lib', 'outside'],
            'Gone\\' => 'shop/missing/',
        ],
        'classmap' => ['shop/lib/', '../'],
        'files' => ['shop/helpers.php'],
    ], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'shop/']]]));

    expect(ComposerSources::of($root, "{$root}/shop"))->toBe([
        "{$root}/shop/app",
        "{$root}/shop/database",
        "{$root}/shop/helpers.php",
        "{$root}/shop/lib",
    ])
        ->and(ComposerSources::of($root, "{$root}/docs"))->toBe(["{$root}/docs/app"])
        ->and(ComposerSources::of("{$root}/shop", "{$root}/shop"))->toBe([], 'no composer.json there')
        ->and(ComposerSources::of("{$root}/nowhere", "{$root}/shop"))->toBe([]);

    file_put_contents("{$root}/composer.json", '{not json');
    expect(ComposerSources::of($root, "{$root}/shop"))->toBe([]);
});

// ------------------------------------------------------------------ running

/**
 * `tinvar dev` against the fake console, in a process of its own.
 *
 * @param list<string> $arguments
 * @param array<string, string> $extraEnv
 *
 * @return array{resource, resource, string, string} the process, its standard output, the log prefix, the watched directory
 */
function started(string $app, array $arguments = [], array $extraEnv = [], ?string $log = null): array
{
    $log ??= sys_get_temp_dir() . '/tinvar-dev-log-' . bin2hex(random_bytes(4));
    $watch = "{$app}/app";
    @mkdir($watch);
    file_put_contents("{$watch}/Data.php", '<?php');
    $env = array_diff_key(array_filter(getenv(), \is_string(...)), array_flip(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']));
    $process = proc_open(
        [PHP_BINARY, RUNNER, "--app={$app}", ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "{$log}.err", 'w']],
        $pipes,
        $app,
        [...$env, ...$extraEnv, 'DEV_TEST_LOG' => $log, 'DEV_TEST_WATCH' => $watch],
    );
    expect($process)->toBeResource();
    stream_set_blocking($pipes[1], false);

    return [$process, $pipes[1], $log, $watch];
}

function waitFor(\Closure $condition, float $seconds = 10): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        clearstatcache();
        if ($condition()) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

/** @param resource $process */
function stopped($process, float $seconds = 20): ?int
{
    $code = null;
    waitFor(static function () use ($process, &$code): bool {
        $status = proc_get_status($process);
        $code = $status['running'] ? null : $status['exitcode'];

        return !$status['running'];
    }, $seconds);
    if ($code === null) {
        proc_terminate($process, 9);
    }
    proc_close($process);

    return $code;
}

function cleanUp(string $log): void
{
    foreach (glob("{$log}*") ?: [] as $file) {
        @unlink($file);
    }
}

it('runs serve --dev with the toolbar\'s run, codegen at the start and on each change, and stops it all', function () {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not available.');
    }
    $app = app();
    [$process, $stdout, $log, $watch] = started($app, ['--port=6123']);

    try {
        $output = '';
        $listening = waitFor(static function () use ($stdout, &$output): bool {
            $output .= (string) stream_get_contents($stdout);

            return str_contains($output, 'Listening on http://127.0.0.1:65000');
        }, 20);
        expect($listening)->toBeTrue('serve\'s URL reached standard output: ' . @file_get_contents("{$log}.err"));

        $serve = json_decode((string) file_get_contents("{$log}.serve"), true);
        \assert(\is_array($serve));
        expect($serve['arguments'])->toBe(['--dev', "--app={$app}", '--port=6123'])
            // The run: a private directory, with a key in it, named to serve.
            ->and($serve['run'])->toStartWith('/tmp/tinvar-dev-')
            ->and($serve['mode'])->toBe(0700)
            ->and($serve['key'])->toBe(64)
            // Codegen ran once before serve started, into the app's types.
            ->and(file("{$log}.codegen", FILE_IGNORE_NEW_LINES))->toBe(["--out={$app}/resources/types"]);

        // An edit to the PHP: codegen again, once the writes settle.
        file_put_contents("{$watch}/Data.php", '<?php final class Data {}');
        // Its report is written once it has run, after the line it logged.
        expect(waitFor(static fn (): bool => str_contains((string) file_get_contents("{$log}.err"), '[codegen] after Data.php: wrote /types/generated.ts (120 bytes)')))
            ->toBeTrue('codegen ran after the edit: ' . @file_get_contents("{$log}.err"))
            ->and(file("{$log}.codegen") ?: [])->toHaveCount(2);

        // A failed codegen is reported, and dev carries on.
        touch("{$log}.fail");
        file_put_contents("{$watch}/Broken.php", '<?php }');
        expect(waitFor(static fn (): bool => str_contains((string) file_get_contents("{$log}.err"), '[codegen] failed after Broken.php (status 255)')))->toBeTrue()
            ->and((string) file_get_contents("{$log}.err"))->toContain('PHP Parse error: syntax error')
            ->and(proc_get_status($process)['running'])->toBeTrue();

        posix_kill(proc_get_status($process)['pid'], SIGTERM);
        $code = stopped($process);

        expect($code)->toBe(0)
            ->and(is_file("{$log}.stopped"))->toBeTrue('serve was stopped, not left running')
            ->and(posix_kill($serve['pid'], 0))->toBeFalse()
            // The run, its key with it, is gone.
            ->and(is_dir($serve['run']))->toBeFalse();
    } finally {
        // Still open only when an expectation failed before it was stopped.
        if (\is_resource($process) && proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        cleanUp($log);
    }
});

it('stops, and says so, when serve exits on its own', function () {
    if (!\function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is not available.');
    }
    [$process, $stdout, $log] = started(app());

    try {
        expect(waitFor(static fn (): bool => is_file("{$log}.serve"), 20))->toBeTrue();
        $run = json_decode((string) file_get_contents("{$log}.serve"), true)['run'] ?? '';
        touch("{$log}.crash");

        expect(stopped($process))->toBe(3)
            ->and((string) file_get_contents("{$log}.err"))->toContain('tinvar serve exited with status 3; stopping.')
            ->and(is_dir($run))->toBeFalse();
    } finally {
        cleanUp($log);
    }
});

// A fixed fifteen seconds was shorter than serve's own stop -- ten for
// FrankenPHP and ten for the renderer, and the stop budget for a job in hand
// -- so dev killed serve before serve could stop what it started.
it('gives serve longer to stop than serve gives what it started', function () {
    $serve = \Tinvar\Server\ServeCommand::class;
    $alone = $serve::stopSeconds(['the dev renderer', 'FrankenPHP']);
    $working = $serve::stopSeconds(['the dev renderer', 'FrankenPHP', 'queue:work', 'schedule:work'], 90);

    expect(DevCommand::stopSeconds(['serve', '--dev'], 90))->toBeGreaterThan($alone)
        ->and($alone)->toBe(2 * $serve::STOP_SECONDS)
        ->and(DevCommand::stopSeconds(['serve', '--dev', '--worker'], 90))->toBeGreaterThan($working)
        ->and(DevCommand::stopSeconds(['serve', '--dev', '--schedule'], 90))->toBeGreaterThan($working)
        ->and($working)->toBe(90 + 2 * $serve::STOP_SECONDS);
});
