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

it('navigates between racks in place, through the RSC payload', function () use ($root) {
    $seeded = json_decode((string) file_get_contents("{$root}/examples/racks/database/seeded.json"), true);
    [$a, $b] = [$seeded['racks'][0], $seeded['racks'][1]];

    $renderer = Renderer::start('msgpack', 'browser-e2e');
    $port = 18_000 + random_int(0, 999);
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/racks/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root,
        [...getenv(), 'TINVAR_SOCKET' => $renderer->socketPath],
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

        $output = shell_exec(
            'node ' . escapeshellarg("{$root}/examples/racks/e2e/navigation.mjs")
            . ' ' . escapeshellarg("http://127.0.0.1:{$port}")
            . ' ' . escapeshellarg($a) . ' ' . escapeshellarg($b) . ' 2>&1',
        );
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
    }

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
