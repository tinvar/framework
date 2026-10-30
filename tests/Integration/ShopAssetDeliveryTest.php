<?php

declare(strict_types=1);

/**
 * The shop's assets as FrankenPHP and Chromium take them, from a build held
 * to its digests (TINVAR_ASSET_INTEGRITY=1, ADR 0108).
 *
 *  - Before the page renders, a 103 Early Hint names the stylesheet every
 *    page links and the client entry, each with its digest; after, the
 *    page's Link header names its modules with theirs. A header's preload
 *    is fetched before the import map is read, and Chromium holds it to the
 *    digest the header gives, or to none.
 *  - The stylesheet's <link> carries its digest, and Chromium refuses one
 *    changed after the build -- as it refuses a changed script.
 *  - Caddy serves a name the build writes under /assets/, and nothing else
 *    the build's client directory holds.
 *
 * Skipped without FrankenPHP, Playwright's Chromium or the JavaScript
 * dependencies.
 */

namespace Tinvar\Tests\Integration\ShopAssetDelivery;

use Tinvar\Bench\Shop;
use Tinvar\Http\AssetLinks;
use Tinvar\Http\BuiltAssets;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    try {
        FrankenPhp::locate($root);
    } catch (ServeException) {
        test()->markTestSkipped('No FrankenPHP binary (tools/frankenphp, TINVAR_FRANKENPHP or the PATH).');
    }
    $unbuilt = Shop::ensureIntegrityBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * `tinvar serve` on the shop, from $build, for one test.
 *
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, string $build, \Closure $drive): void
{
    $keyDir = sys_get_temp_dir() . '/tinvar-assets-' . bin2hex(random_bytes(4));
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-assets-log-');

    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', "--app={$root}/examples/shop", '--workers=2', '--renderers=1'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            'TINVAR_BUILD_DIR' => $build,
        ],
    );
    stream_set_blocking($pipes[1], false);

    try {
        $printed = '';
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+:\d+)#', $printed, $m) !== 1) {
            $printed .= (string) stream_get_contents($pipes[1]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        expect($m[1] ?? null)->not->toBeNull(
            "tinvar serve printed no URL:\n{$printed}\n" . implode('', \array_slice(file($log) ?: [], -20)),
        );

        $drive($m[1]);
    } finally {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        $deadline = microtime(true) + 20;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        proc_close($process);
        @unlink($log);
        @unlink("{$keyDir}/app.key");
        @unlink("{$keyDir}/.app.key.lock");
        @rmdir($keyDir);
    }
}

/** Every byte of the answer to GET $path, informational responses and all. */
function raw(string $origin, string $path): string
{
    $address = (string) preg_replace('#^http://#', 'tcp://', $origin);
    $stream = stream_socket_client($address, $errno, $error, 10);
    if ($stream === false) {
        throw new \RuntimeException("could not connect to {$origin}: {$error}");
    }
    stream_set_timeout($stream, 20);
    fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/html\r\nConnection: close\r\n\r\n");
    $response = (string) stream_get_contents($stream);
    fclose($stream);

    return $response;
}

/** The headers of each response in $raw, in order: [status, [name => value]]. */
function heads(string $raw): array
{
    $heads = [];
    while (preg_match('#^HTTP/1\.1 (\d{3})[^\r]*\r\n(.*?)\r\n\r\n#s', $raw, $m) === 1) {
        $headers = [];
        foreach (explode("\r\n", $m[2]) as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }
        $heads[] = [(int) $m[1], $headers];
        $raw = substr($raw, \strlen($m[0]));
        if ((int) $m[1] >= 200) {
            break;
        }
    }

    return $heads;
}

/** @return array<string, mixed> what integrity.mjs printed */
function chromium(string $root, string $origin): array
{
    if (!is_file("{$root}/node_modules/playwright/package.json")) {
        test()->markTestSkipped('Playwright is not installed.');
    }
    $output = [];
    exec(implode(' ', array_map(escapeshellarg(...), ['node', "{$root}/examples/shop/e2e/integrity.mjs", $origin])) . ' 2>&1', $output, $status);
    $printed = implode("\n", $output);
    $result = json_decode((string) end($output), true);
    if ($status !== 0 || !\is_array($result)) {
        throw new \RuntimeException("integrity.mjs failed:\n{$printed}");
    }

    return $result;
}

it('hints the first loads with their digests, and names each module with its own after', function () use ($root) {
    $links = AssetLinks::fromBuild(new BuiltAssets(Shop::integrityBuild()));

    served($root, Shop::integrityBuild(), function (string $origin) use ($links): void {
        $response = raw($origin, '/');
        [$hint, $page] = heads($response) + [1 => [0, []]];

        expect($hint[0])->toBe(103)
            ->and(array_diff(array_keys($hint[1]), ['link', 'server', 'date']))->toBe([])
            ->and($hint[1]['link'] ?? '')->toBe((string) $links->earlyHints())
            ->and($hint[1]['link'] ?? '')->toContain('rel=preload; as=style; crossorigin; integrity="sha384-')
            ->and($page[0])->toBe(200);

        // Every entry of the page's own header with its digest.
        $entries = explode(', ', $page[1]['link'] ?? '');
        expect($entries)->not->toBe([''])
            ->and(array_filter($entries, static fn (string $entry): bool => !str_contains($entry, '; integrity="sha384-')))->toBe([]);

        // The stylesheet's link, with the digest of the file Caddy serves.
        expect(preg_match('#<link rel="stylesheet"[^>]* href="(/assets/index-[\w-]+\.css)"[^>]* integrity="(sha384-[^"]+)"#', $response, $m))->toBe(1);
        $sheet = raw($origin, $m[1]);
        $body = substr($sheet, (int) strpos($sheet, "\r\n\r\n") + 4);
        expect(heads($sheet)[0][0] ?? null)->toBe(200)
            ->and('sha384-' . base64_encode(hash('sha384', $body, true)))->toBe($m[2]);
    });
});

it('holds the stylesheet and the scripts to their digests in Chromium, and serves only the names a build writes', function () use ($root) {
    // A copy, beside the build so the renderer resolves the shop's packages
    // from it, changed after the build as a CDN rewriting files would.
    $copy = Shop::integrityBuild() . '-changed-' . bin2hex(random_bytes(4));
    exec('cp -R ' . escapeshellarg(Shop::integrityBuild()) . ' ' . escapeshellarg($copy));

    try {
        served($root, Shop::integrityBuild(), function (string $origin) use ($root): void {
            $page = chromium($root, $origin);

            expect($page['status'])->toBe(200)
                ->and($page['importMap'])->toBeTrue()
                ->and($page['sheet'])->toMatchArray(['applied' => true])
                ->and($page['sheet']['integrity'])->toStartWith('sha384-')
                ->and($page['hydrated'])->toBeTrue()
                ->and($page['refused'])->toBe([])
                ->and($page['errors'])->toBe([]);
        });

        // The stylesheet and the client entry every page loads first.
        $assets = "{$copy}/client/assets";
        $first = AssetLinks::fromBuild(new BuiltAssets($copy));
        $sheet = $assets . '/' . basename($first->styles[0] ?? '');
        $entry = $assets . '/' . basename($first->modules[0] ?? '');
        file_put_contents($sheet, "\nbody{display:none}", FILE_APPEND);
        file_put_contents($entry, "\nconsole.log('changed');", FILE_APPEND);
        // What the build never wrote, beside what it did.
        file_put_contents("{$assets}/" . basename($entry) . '.map', '{"sources":["/srv/app/secret.ts"]}');
        file_put_contents("{$assets}/notes.txt", 'not an asset');

        served($root, $copy, function (string $origin) use ($root, $entry): void {
            $page = chromium($root, $origin);

            expect($page['sheet'])->toMatchArray(['applied' => false])
                ->and($page['hydrated'])->toBeFalse()
                ->and(implode("\n", $page['refused']))->toContain('/assets/index-')
                ->and(\count($page['refused']))->toBeGreaterThanOrEqual(2);

            expect(heads(raw($origin, '/assets/' . basename($entry)))[0][0] ?? null)->toBe(200)
                ->and(heads(raw($origin, '/assets/' . basename($entry) . '.map'))[0][0] ?? null)->not->toBe(200)
                ->and(heads(raw($origin, '/assets/notes.txt'))[0][0] ?? null)->not->toBe(200);
        });
    } finally {
        exec('rm -rf ' . escapeshellarg($copy));
    }
});
