<?php

declare(strict_types=1);

/**
 * `tinvar new` for real (ADR 0155): run non-interactively from this
 * checkout's bin/tinvar in a temporary directory, the app it makes --
 * installed by Composer and npm, migrated and seeded, its key and probe
 * token in a state directory of the run's own -- then built and served by
 * its own bin/serve through FrankenPHP, and its home page fetched.
 *
 * Skipped without the repository's checkout (a package's run alone),
 * Composer, npm or a FrankenPHP binary. Composer and npm resolve from
 * their caches and registries, as a person's would.
 */

namespace Tinvar\Tests\Console\NewApp\EndToEnd;

use Tinvar\Console\NewApp\Skeletons;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

/** What stops this test running here, or null. */
function missing(): ?string
{
    $workspace = Skeletons::workspace();
    if ($workspace === null) {
        return 'the repository\'s checkout';
    }
    foreach (['composer', 'npm'] as $program) {
        exec('command -v ' . escapeshellarg($program) . ' 2>/dev/null', $found, $status);
        if ($status !== 0) {
            return $program;
        }
    }
    try {
        FrankenPhp::locate($workspace);
    } catch (ServeException) {
        return 'a FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH)';
    }

    return null;
}

/**
 * Runs $command in $directory, its output kept; fails the test with it
 * where it exits other than 0.
 *
 * @param list<string> $command
 * @param array<string, string> $env
 */
function must(array $command, string $directory, array $env): string
{
    // Both streams appended to one file: each write lands at its end, in
    // the order made, and neither pipe can fill while the other is read.
    $file = (string) tempnam(sys_get_temp_dir(), 'tinvar-new-e2e-');
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $file, 'a'], 2 => ['file', $file, 'a']], $pipes, $directory, $env);
    \assert(\is_resource($process));
    $status = proc_close($process);
    $output = (string) file_get_contents($file);
    unlink($file);
    expect($status)->toBe(0, implode(' ', $command) . " failed in {$directory}:\n" . implode("\n", \array_slice(explode("\n", rtrim($output)), -40)));

    return $output;
}

/**
 * Every key file inside the app, its dependencies aside: none, since a
 * key is kept outside the project.
 *
 * @return list<string>
 */
function keysInside(string $app): array
{
    $found = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS)) as $file) {
        \assert($file instanceof \SplFileInfo);
        $path = $file->getPathname();
        if (preg_match('#/(vendor|node_modules|\.packages)/#', $path) !== 1 && preg_match('/\.(key|probe-token|database-password)\z/', $path) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

it('makes an app with tinvar new, then builds it, serves it and answers its home page', function () {
    $workspace = (string) Skeletons::workspace();
    $work = sys_get_temp_dir() . '/tinvar-new-e2e-' . bin2hex(random_bytes(4));
    mkdir($work, 0o700);
    $work = (string) realpath($work);
    $app = "{$work}/my-app";
    $env = [
        ...array_diff_key(array_filter(getenv(), \is_string(...)), array_flip(['TINVAR_APP_KEY', 'TINVAR_APP_KEY_FILE', 'TINVAR_PROBE_TOKEN', 'TINVAR_PROBE_TOKEN_FILE', 'APP_DATABASE', 'TINVAR_ENV', 'TINVAR_PROFILE', 'TINVAR_BUILD_DIR', 'TINVAR_SOCKET'])),
        // The key and the probe token go where a person's would: this run's.
        'XDG_STATE_HOME' => "{$work}/state",
        'TINVAR_FRANKENPHP' => FrankenPhp::locate($workspace),
    ];
    $server = null;

    try {
        $made = must([PHP_BINARY, "{$workspace}/bin/tinvar", 'new', 'my-app', '--no-interaction'], $work, $env);
        expect($made)->toContain('my-app is made.')
            ->and(glob("{$work}/state/tinvar/my-app-*.key") ?: [])->toHaveCount(1)
            ->and(keysInside($app))->toBe([])
            ->and(is_file("{$app}/database/app.sqlite"))->toBeTrue()
            ->and((string) file_get_contents("{$app}/.env"))->toContain('TINVAR_ENV=local');

        // The doctor of a new app finds nothing it cannot fix.
        $doctor = must([PHP_BINARY, 'bin/tinvar', 'doctor', '--fix'], $app, $env);
        expect($doctor)->toContain('No problems.');

        must(['npm', 'run', 'build'], $app, $env);

        $log = "{$work}/serve.log";
        $server = proc_open(['bin/serve', '--workers=2', '--renderers=1'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $app, $env);
        \assert(\is_resource($server));
        $url = null;
        $deadline = microtime(true) + 120;
        while ($url === null && microtime(true) < $deadline && proc_get_status($server)['running']) {
            if (preg_match('#Listening on (http://127\.0\.0\.1:\d+)#', (string) @file_get_contents($log), $m) === 1) {
                $url = $m[1];
            } else {
                usleep(250_000);
            }
        }
        expect($url)->not->toBeNull('bin/serve never said where it listens:' . "\n" . (string) @file_get_contents($log));

        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]);
        $body = (string) file_get_contents("{$url}/", false, $context);
        $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
        $token = trim((string) file_get_contents((glob("{$work}/state/tinvar/my-app-*.probe-token") ?: [''])[0]));
        file_get_contents("{$url}/_health", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'header' => ["Authorization: Bearer {$token}"]]]));
        $probe = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);

        expect($status)->toBe(200, (string) @file_get_contents($log))
            ->and($body)->toContain('Welcome. This note came from the seeder')
            ->and($probe)->toBe(200);
    } finally {
        if (\is_resource($server)) {
            proc_terminate($server, SIGTERM);
            $stopped = microtime(true) + 30;
            while (proc_get_status($server)['running'] && microtime(true) < $stopped) {
                usleep(200_000);
            }
            if (proc_get_status($server)['running']) {
                proc_terminate($server, SIGKILL);
            }
            proc_close($server);
        }
        exec('rm -rf ' . escapeshellarg($work));
    }
})->skip(missing() !== null, 'needs ' . (missing() ?? 'nothing'));
