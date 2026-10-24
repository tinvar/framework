<?php

declare(strict_types=1);

/**
 * `tinvar dev` for real: serve --dev under it, codegen at the start, and the
 * dev toolbar over the shop's pages in Chromium -- its script and style run
 * under each response's nonce, it reads the session's requests through PHP
 * on the page's own origin, and shows the catalog's props, timings and
 * frames, and a client navigation's ticks under the visit.
 *
 * Against a copy of the shop's frontend, as ServeDevTest is: codegen writes
 * the copy's types, never the shop's own. Skipped without a FrankenPHP
 * binary, the JavaScript dependencies or Playwright.
 */

namespace Tinvar\Tests\Server\DevIntegration;

use Tinvar\Bench\Shop;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
    if (!is_file("{$root}/node_modules/vite/package.json")) {
        test()->markTestSkipped('The JavaScript dependencies are not installed (npm ci).');
    }
    if (!is_file("{$root}/node_modules/playwright/package.json")) {
        test()->markTestSkipped('Playwright is not installed.');
    }
});

/** A copy of the shop's frontend beside it, its node_modules linked; see ServeDevTest. */
function copyOfShop(string $root): string
{
    $copy = "{$root}/examples/.dev-" . bin2hex(random_bytes(4));
    $shop = "{$root}/examples/shop";
    exec(sprintf(
        'mkdir -p %1$s && cp -R %2$s/resources %2$s/public %2$s/vite.config.ts %2$s/package.json %2$s/tsconfig.json %1$s/',
        escapeshellarg($copy),
        escapeshellarg($shop),
    ), $output, $status);
    expect($status)->toBe(0, 'could not copy the shop');

    mkdir("{$copy}/node_modules");
    foreach (glob("{$shop}/node_modules/{,.}*", GLOB_BRACE) ?: [] as $entry) {
        $name = basename($entry);
        if (!\in_array($name, ['.', '..', '.vite'], true)) {
            symlink($entry, "{$copy}/node_modules/{$name}");
        }
    }

    return $copy;
}

/**
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

it('supervises the shop in dev, and draws the toolbar over its pages', function () use ($root) {
    $copy = copyOfShop($root);
    $keyDir = sys_get_temp_dir() . '/tinvar-dev-it-' . bin2hex(random_bytes(4));
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-dev-log-');
    $types = (string) file_get_contents("{$copy}/resources/types/generated.ts");
    $typesTime = filemtime("{$copy}/resources/types/generated.ts");

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'dev', "--app={$copy}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true, 'NODE_ENV' => true, 'APP_ENV' => true, 'TINVAR_ENV' => true]),
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
        ],
    );
    stream_set_blocking($pipes[1], false);

    try {
        // Past dev's own waits -- codegen's 60 s, then serve's 180 for the
        // dev renderer and 30 for FrankenPHP -- so a start that fails says
        // why in their words rather than being stopped mid-load by this.
        $output = '';
        $deadline = microtime(true) + 300;
        while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $output, $m) !== 1) {
            $output .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(100_000);
        }
        expect($m[1] ?? null)->not->toBeNull("tinvar dev printed no URL:\n" . file_get_contents($log));
        $url = $m[1];

        $browsed = runWithTimeout(['node', "{$root}/examples/shop/e2e/dev-toolbar.mjs", $url], $root, 150);
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        $until = microtime(true) + 30;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $until) {
            usleep(50_000);
        }
        $code = $status['running'] ? null : $status['exitcode'];
        if ($status['running']) {
            proc_terminate($process, SIGKILL);
        }
        fclose($pipes[1]);
        proc_close($process);
        $stderr = (string) file_get_contents($log);
        @unlink($log);
        exec('rm -rf ' . escapeshellarg($copy) . ' ' . escapeshellarg($keyDir));
    }

    // Stopped by its signal, everything with it; codegen ran first, into the
    // copy's types, and wrote nothing that had not changed.
    expect($code)->toBe(0, $stderr)
        ->and($stderr)->toContain('[codegen] types up to date at start')
        ->and($types)->not->toBe('');
    clearstatcache();

    $result = json_decode($browsed, true);
    expect($result)->toBeArray("the browser script failed:\n{$browsed}\n{$stderr}");
    \assert(\is_array($result));

    // Its script ran and its style applied, under the nonce, with nothing refused.
    expect($result['errors'])->toBe([])
        ->and($result['violations'])->toBe([])
        ->and($result['styled'])->toBe('fixed');

    // The catalog, as the renderer was given it.
    expect($result['catalog']['selected'])->toBe('/')
        ->and($result['catalog']['props'])->toContain('Sent to the renderer · render')
        ->and($result['catalog']['props'])->toContain('"route": "shop/catalog"')
        ->and($result['catalog']['props'])->toContain('"products"')
        ->and($result['catalog']['props'])->not->toContain('"nonce": "')
        ->and($result['catalog']['timings'])->toContain('· in the renderer')
        ->and($result['catalog']['frames'])->toContain('PHP → renderer')
        ->and($result['catalog']['frames'])->toContain('renderer → PHP');

    // A client navigation, under the visit, with its availability's tick.
    expect($result['product']['kind'])->toBe('payload')
        ->and($result['product']['ticks'])->toContain('products.availability');
});
