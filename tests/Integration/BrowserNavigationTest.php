<?php

declare(strict_types=1);

/**
 * The client router against the real stack, in a real browser: PHP behind
 * php -S through the Sapi adapter, the Node renderer over its socket, the
 * built client bundle, Chromium.
 *
 * Every router test before this drove the router against a stubbed fetch
 * and a stubbed mount. This one is the only place the server's half and the
 * client's half of the protocol meet -- RSC: 1, X-Version from the page
 * metadata, a text/x-component payload, a mount that commits.
 *
 * Skipped when its prerequisites are missing: the build, the seed data, or
 * Playwright's Chromium.
 */

use Tinvar\Bench\Renderer;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    foreach ([
        "{$root}/examples/racks/dist/rsc/index.js" => 'The Racks app is not built.',
        "{$root}/examples/racks/database/seeded.json" => 'No seed data.',
        "{$root}/node_modules/playwright/package.json" => 'Playwright is not installed.',
    ] as $file => $why) {
        if (!is_file($file)) {
            test()->markTestSkipped($why);
        }
    }
});

/**
 * Runs a command and returns its combined output, killing it past the
 * deadline -- a browser that hangs on launch must fail the test, not hang it.
 *
 * @param list<string> $command
 */
function runWithTimeout(array $command, string $cwd, int $seconds): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    stream_set_blocking($pipes[1], false);

    $output = '';
    $deadline = microtime(true) + $seconds;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        $output .= (string) stream_get_contents($pipes[1]);
        usleep(50_000);
    }
    $output .= (string) stream_get_contents($pipes[1]);

    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
        $output .= "\n[timed out after {$seconds}s]";
    }
    proc_close($process);

    return $output;
}

/**
 * The served app for one test: a renderer, and php -S on the front
 * controller with deferred boundaries enabled.
 *
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, \Closure $drive): void
{
    $renderer = Renderer::start('msgpack', 'browser-e2e');
    $port = 18_000 + random_int(0, 999);
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/racks/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root,
        [...getenv(), 'TINVAR_SOCKET' => $renderer->socketPath, 'TINVAR_APP_KEY' => 'browser-test-key-0123456789abcdef0123'],
    );

    try {
        // php -S is up once it answers. Refused connections until then are
        // expected, not warnings.
        set_error_handler(static fn (): bool => true);
        try {
            $deadline = microtime(true) + 10;
            while (fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }

        // A server that exited -- the port was taken -- would leave the
        // browser talking to whatever else holds the port.
        expect(proc_get_status($server)['running'])->toBeTrue("php -S did not start on port {$port}");

        $drive("http://127.0.0.1:{$port}");
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
    }
}

it('navigates between racks in place, through the RSC payload', function () use ($root) {
    $seeded = json_decode((string) file_get_contents("{$root}/examples/racks/database/seeded.json"), true);
    [$a, $b] = [$seeded['racks'][0], $seeded['racks'][1]];

    $output = '';
    served($root, function (string $origin) use ($root, $a, $b, &$output): void {
        $output = runWithTimeout(['node', "{$root}/examples/racks/e2e/navigation.mjs", $origin, $a, $b], $root, 60);
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['first'])->not->toBe($result['second'])
        ->and($result['path'])->toBe("/racks/{$b}")
        ->and($result['units'])->toBe(42);

    // One document load, then one client navigation that asked for the
    // payload and named the build the page metadata gave it.
    expect($result['requests'])->toBe([
        ['path' => "/racks/{$a}", 'rsc' => null, 'version' => null],
        ['path' => "/racks/{$b}", 'rsc' => '1', 'version' => 'dev'],
    ]);
});

// The Suspense boundary, for real: server-rendered with its fallback, its
// subtree not rendered with the page, then -- scrolled into view -- fetched
// from /_fragment/ with a signed token, decoded by the app's own bundle and
// swapped in.
it('loads a deferred boundary when it scrolls into view', function () use ($root) {
    $seeded = json_decode((string) file_get_contents("{$root}/examples/racks/database/seeded.json"), true);

    $output = '';
    served($root, function (string $origin) use ($root, $seeded, &$output): void {
        $output = runWithTimeout(['node', "{$root}/examples/racks/e2e/deferred.mjs", $origin, $seeded['racks'][0]], $root, 60);
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['before'])->toBe('Loading history…')
        ->and($result['requestedBeforeScroll'])->toBe(0)
        ->and($result['after'])->toBe('Recent changes')
        ->and($result['fragments'])->toBe(['1']);
});
