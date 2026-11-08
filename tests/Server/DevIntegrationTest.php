<?php

declare(strict_types=1);

/**
 * `tinvar serve --dev` for real: serve --dev under it, codegen at the start, and the
 * dev toolbar over the shop's pages in Chromium -- its script and style run
 * under each response's nonce, it reads the session's requests through PHP
 * on the page's own origin, and shows the catalog's props, timings and
 * frames, and a client navigation's ticks under the visit. Then the same
 * through the DevTools extension, loaded unpacked (ADR 0072), and in Firefox
 * where Playwright's Firefox is installed (ADR 0132).
 *
 * Against a copy of the shop's frontend, as ServeDevTest is: codegen writes
 * the copy's types, never the shop's own. Skipped without a FrankenPHP
 * binary, the JavaScript dependencies or Playwright.
 */

namespace Tinvar\Tests\Server\DevIntegration;

use Shop\Kernel as ShopKernel;
use Tinvar\Bench\Shop;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\FragmentManifestProducer;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Server\FrankenPhp;
use Tinvar\Server\ServeException;
use Tinvar\Testing\Dev\DevToolsCommand;

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
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', '--dev', "--app={$copy}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true, 'NODE_ENV' => true, 'APP_ENV' => true, 'TINVAR_ENV' => true]),
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
            // The toolbar links each file:line to this editor (ADR 0132).
            'TINVAR_EDITOR' => 'vscode://file{file}:{line}',
            // A developer's machine, where the workbench is mounted (ADR 0089).
            'TINVAR_ENV' => 'local',
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
        expect($m[1] ?? null)->not->toBeNull("tinvar serve --dev printed no URL:\n" . file_get_contents($log));
        $url = $m[1];

        $browsed = runWithTimeout(['node', "{$root}/examples/shop/e2e/dev-toolbar.mjs", $url], $root, 150);
        $extension = runWithTimeout(['node', "{$root}/examples/shop/e2e/devtools-extension.mjs", $url], $root, 150);
        // Firefox, where Playwright's own build of it is installed.
        $firefox = firefoxInstalled($root)
            ? runWithTimeout(['node', "{$root}/examples/shop/e2e/devtools-extension-firefox.mjs", $url], $root, 150)
            : null;
        // render --dump --dev: the catalog rendered through this run's dev
        // server, found by its record, on a copy of the database (ADR 0132).
        [$dumpStatus, $dumped, $dumpErr, $dumpHanded] = devDump($copy);
        // The workbench, drawing with the shop's client script (ADR 0132).
        $workbench = runWithTimeout(['node', "{$root}/examples/shop/e2e/workbench-script.mjs", $url], $root, 150);
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
    // copy's types, and wrote nothing that had not changed -- but entries.json,
    // which names each PHP file from where the types are, and the copy's types
    // are not the shop's.
    preg_match('/^\[codegen\] (?:types up to date at start|at start: (.*))$/m', $stderr, $codegen);
    $rewritten = array_filter(explode('; ', $codegen[1] ?? ''), static fn (string $line): bool => $line !== ''
        && !str_ends_with(explode(' (', $line)[0], '/resources/types/entries.json'));
    expect($code)->toBe(0, $stderr)
        ->and($codegen)->not->toBe([], $stderr)
        ->and($rewritten)->toBe([])
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
    // A page and a component hydrated with the shop's client script, in an
    // origin of their own: no cookie, no fetch, no reach into the workbench,
    // a link that stays put; styled, its fonts loaded. And a page elsewhere
    // can neither frame a render nor read a module as a sandboxed frame.
    $bench = json_decode($workbench, true);
    expect($bench)->toBeArray("the workbench script failed:\n{$workbench}\n{$stderr}");
    \assert(\is_array($bench));
    expect($bench['errors'])->toBe([]);
    foreach (['component', 'page'] as $drawn) {
        expect($bench['results'][$drawn])->toMatchArray([
            'sandbox' => 'allow-scripts',
            'origin' => 'null',
            'hydrated' => true,
            'cookie' => 'refused: SecurityError',
            'fetched' => 'refused: TypeError',
            'parent' => 'refused: SecurityError',
            'styled' => true,
        ])
            ->and($bench['results'][$drawn]['afterClick'])->toBe($bench['results'][$drawn]['url'])
            ->and($bench['results'][$drawn]['fonts'])->toBeGreaterThan(0);
    }
    expect($bench['results']['elsewhere'])->toBe(['module' => 'refused', 'framedLoaded' => false]);

    // The dump came from the dev server: its module script, from source.
    $dump = json_decode($dumped, true);
    expect($dumpStatus)->toBe(0, $dumpErr)
        ->and($dump['status'] ?? null)->toBe(200)
        ->and($dump['html'])->toContain('Pellwick Supply')
        ->and($dump['html'])->toContain('/_vite/@id/__x00__virtual:vite-rsc/entry-browser')
        ->and($dump['html'])->not->toMatch('/nonce="(?!\[redacted\])/')
        ->and($dump['payload'])->not->toBe('')
        // On a copy, its files kept beside it (ADR 0173).
        ->and($dumpHanded['database'] ?? null)->toEndWith('/snapshot.sqlite')
        ->and($dumpHanded['files'] ?? null)->toBe($dumpHanded['database'] ?? null);

    // Each file:line a link to the editor TINVAR_EDITOR names, and the
    // catalog's fields with the lines of its loader that made them.
    expect($result['catalog']['props'])->toContain('made at')
        ->and($result['catalog']['links'])->toContain('vscode://file' . implode('/', array_map(rawurlencode(...), explode('/', "{$root}/examples/shop/app/Http/Pages/Catalog/Catalog.php"))) . ':' . lineOf("{$root}/examples/shop/app/Http/Pages/Catalog/Catalog.php", 'products: array_map('));

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

    // Named by the component that asked, and the one that rendered it, from
    // the dev renderer's owner stack (ADR 0062); and the page's props by the
    // loader that returned them.
    expect($result['product']['ticks'])->toContain('from OrderPanel')
        ->and($result['product']['ticks'])->toContain('OrderPanel ← Page')
        ->and($result['catalog']['props'])->toContain('Where each came from')
        ->and($result['catalog']['props'])->toContain('from Catalog::__invoke');

    // The DevTools extension, loaded unpacked, reads the same through its
    // content script: the visit and its badges, editor links once an editor
    // is chosen, and the client navigation under the visit with its owners.
    $panel = json_decode($extension, true);
    expect($panel)->toBeArray("the extension's browser script failed:\n{$extension}\n{$stderr}");
    \assert(\is_array($panel));

    expect($panel['errors'])->toBe([])
        ->and($panel['violations'])->toBe([])
        // The panel's pages reach nothing but the extension's own files.
        ->and($panel['offsite'])->toBe([])
        ->and($panel['catalog']['origin'])->toBe($url)
        ->and($panel['catalog']['document'])->toBe('/')
        ->and($panel['catalog']['badges'])->toContain('page')
        ->and($panel['catalog']['props'])->toContain('from Catalog::__invoke')
        ->and($panel['catalog']['props'])->toContain('"products"')
        ->and($panel['catalog']['props'])->not->toContain('"nonce": "')
        ->and($panel['catalog']['linksBefore'])->toBe(0)
        ->and($panel['catalog']['link'])->toStartWith('vscode://file/')
        ->and($panel['catalog']['timings'])->toContain('· in the renderer');
    expect($panel['product']['kind'])->toBe('payload')
        ->and($panel['product']['underVisit'])->toBeGreaterThan(0)
        ->and($panel['product']['ticks'])->toContain('products.availability')
        ->and($panel['product']['ticks'])->toContain('from OrderPanel ← Page');

    // Not `tinvar serve --dev`: a page with no toolbar is not read, and a forged
    // token where no endpoint answers -- as in production -- reads nothing.
    expect($panel['elsewhere']['plain'])->toContain('not served by `tinvar serve --dev`')
        ->and($panel['elsewhere']['forged'])->toContain('not a Tinvar dev server');

    // Firefox, from the same src/: installed as a temporary add-on, which is
    // where Firefox reads the manifest; its content script reads the
    // endpoint through content.fetch, and the background relays it as a
    // panel would be handed it -- the catalog's fields with the loader's
    // lines -- and reads nothing elsewhere.
    if ($firefox === null) {
        return;
    }
    $fox = json_decode($firefox, true);
    expect($fox)->toBeArray("the Firefox script failed:\n{$firefox}\n{$stderr}");
    \assert(\is_array($fox));
    $made = array_column($fox['catalog']['fields'], 'made', 'name');
    expect($fox['errors'])->toBe([])
        ->and($fox['manifest']['id'])->toBe('devtools@tinvar.dev')
        ->and($fox['list']['ok'])->toBeTrue()
        ->and($fox['list']['origin'])->toBe($url)
        ->and($fox['list']['paths'])->toContain('/')
        ->and($fox['catalog']['ok'])->toBeTrue()
        ->and($fox['catalog']['loader'])->toBe('Shop\\Http\\Pages\\Catalog\\Catalog::__invoke')
        ->and($made['products'][0] ?? '')->toEndWith('/examples/shop/app/Http/Pages/Catalog/Catalog.php:' . lineOf("{$root}/examples/shop/app/Http/Pages/Catalog/Catalog.php", 'products: array_map('))
        ->and($fox['elsewhere'])->toBe(['plain' => 'no-toolbar', 'forged' => 'not-dev-server']);
});

/**
 * `render --dump / --dev --json` for the app at $app, as the shop's
 * bin/tinvar builds it, through the `tinvar serve --dev` serving it.
 *
 * @return array{int, string, string, array{database?: string, files?: ?string}} the status, what it
 *         printed, what it said on error, and what the application was handed
 */
function devDump(string $app): array
{
    $routes = new RouteRegistry();
    $routes->registerAll(ShopKernel::classes()->routes);
    $out = $err = '';
    $handed = [];
    $before = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=local');
    try {
        $status = new DevToolsCommand(
            app: $app,
            projectRoot: \dirname(Shop::root(), 2),
            database: Shop::database(),
            application: static function (string $socket, string $database, string $key, ?string $build, ?string $dev = null, ?string $files = null) use ($routes, &$handed): ShopKernel {
                $handed = ['database' => $database, 'files' => $files];
                $deferred = $dev ?? "{$build}/rsc/deferred.json";

                return ShopKernel::fromEnvironment(
                    $socket,
                    key: $key,
                    database: $database,
                    manifest: is_file($deferred) ? FragmentManifestProducer::produce($routes, $deferred) : new FragmentManifest(),
                    dev: $dev !== null,
                    mailTransport: new ArrayTransport(),
                );
            },
            env: ['TINVAR_ENV' => 'local'],
            out: static function (string $text) use (&$out): void {
                $out .= $text;
            },
            err: static function (string $line) use (&$err): void {
                $err .= $line . "\n";
            },
        )->render(['--dump', '/', '--dev', '--json']);
    } finally {
        putenv($before === false ? 'TINVAR_ENV' : "TINVAR_ENV={$before}");
    }

    return [$status, $out, $err, $handed];
}

/** Whether Playwright's own Firefox is installed (`npx playwright install firefox`). */
function firefoxInstalled(string $root): bool
{
    $path = shell_exec('cd ' . escapeshellarg($root) . " && node -e \"process.stdout.write(require('playwright').firefox.executablePath())\" 2>/dev/null");

    return \is_string($path) && $path !== '' && is_file($path);
}

/** The 1-based line of $file holding $text. */
function lineOf(string $file, string $text): int
{
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        if (str_contains($line, $text)) {
            return $i + 1;
        }
    }

    return 0;
}
