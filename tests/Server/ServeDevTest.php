<?php

declare(strict_types=1);

/**
 * `tinvar serve --dev` for real: Vite's dev server renders the shop from
 * source, FrankenPHP proxies its modules and HMR socket on its own origin,
 * an edit to a server component and to a client component shows up in
 * Chromium with no reload, and live updates arrive through the hub.
 *
 * Against a copy of the shop's frontend, which the browser script edits:
 * the shop's own sources are what other tests build from, and an edit there
 * -- even one put back -- would have them build again.
 *
 * Skipped without a FrankenPHP binary, the JavaScript dependencies or
 * Playwright.
 */

namespace Tinvar\Tests\Server\ServeDev;

use Shop\DemoAccount;
use Shop\StaffAccount;
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

/**
 * A copy of the shop's frontend beside it, at the same depth, so its front
 * controller finds the autoloader where the shop's does and its modules
 * resolve through the same node_modules. What npm placed under the shop's
 * own node_modules is linked in; the dev server's cache is the copy's own.
 */
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
 * A running `tinvar serve`, its URL, and a stop that returns its exit status.
 *
 * @param list<string> $arguments
 * @param array<string, string> $env
 * @return array{string, \Closure(): ?int, string}
 */
function serve(string $root, array $arguments, array $env): array
{
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-serve-dev-log-');
    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'serve', ...$arguments],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        $root,
        [...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]), ...$env],
    );
    stream_set_blocking($pipes[1], false);

    // Past serve's own waits -- 180 s for the dev renderer, 30 for
    // FrankenPHP -- so a start that fails says why in serve's words, rather
    // than being cut off by this one while it loads.
    $output = '';
    $deadline = microtime(true) + 240;
    while (microtime(true) < $deadline && preg_match('#Listening on (http://\S+)#', $output, $m) !== 1) {
        $output .= (string) stream_get_contents($pipes[1]);
        if (!proc_get_status($process)['running']) {
            break;
        }
        usleep(100_000);
    }

    $stop = static function () use ($process, $pipes, $log): ?int {
        $status = proc_get_status($process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }
        $deadline = microtime(true) + 20;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $code = $status['running'] ? null : $status['exitcode'];
        if ($status['running']) {
            proc_terminate($process, SIGKILL);
        }
        fclose($pipes[1]);
        proc_close($process);
        @unlink($log);

        return $code;
    };

    if (!isset($m[1])) {
        $tail = implode("\n", \array_slice(file($log) ?: [], -30));
        $stop();

        throw new \RuntimeException("tinvar serve printed no URL:\n{$output}\n{$tail}");
    }

    return [$m[1], $stop, $log];
}

/**
 * @param list<string> $headers
 * @return array{int, array<string, string>, string} status, headers by lowercase name, body
 */
function fetch(string $url, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 60,
    ]]);
    $body = (string) @file_get_contents($url, false, $context);
    /** @var list<string> $lines */
    $lines = $http_response_header ?? [];
    preg_match('#^HTTP/\S+ (\d{3})#', $lines[0] ?? '', $m);
    $parsed = [];
    foreach (\array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $parsed[strtolower(trim($name))] = trim($value);
    }

    return [(int) ($m[1] ?? 0), $parsed, $body];
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

it('serves the shop from source, and shows an edited component without a reload', function () use ($root) {
    $copy = copyOfShop($root);
    $keyDir = sys_get_temp_dir() . '/tinvar-serve-dev-' . bin2hex(random_bytes(4));
    $before = [
        'page' => (string) file_get_contents("{$copy}/resources/pages/shop/sign-in.tsx"),
        'form' => (string) file_get_contents("{$copy}/resources/components/SignInForm.tsx"),
    ];

    try {
        [$url, $stop] = serve($root, ['--dev', "--app={$copy}"], [
            'SHOP_DATABASE' => Shop::database(),
            'TINVAR_APP_KEY_FILE' => "{$keyDir}/app.key",
        ]);

        try {
            [$status, $headers, $body] = fetch("{$url}/sign-in", ['Accept: text/html']);
            [$clientStatus, $clientHeaders] = fetch("{$url}/_vite/@vite/client");
            [$missing, , $missingBody] = fetch("{$url}/no-such-shelf", ['Accept: text/html']);
            // A page that points a name of its own at loopback -- DNS
            // rebinding -- names that name, and is refused before anything.
            $port = (string) parse_url($url, PHP_URL_PORT);
            [$rebound] = fetch("{$url}/sign-in", ['Accept: text/html', "Host: rebound.attacker.example:{$port}"]);
            [$reboundModule] = fetch("{$url}/_vite/@vite/client", ["Host: rebound.attacker.example:{$port}"]);
            [$named] = fetch("{$url}/sign-in", ['Accept: text/html', "Host: shop.localhost:{$port}"]);
            $output = runWithTimeout(['node', "{$root}/examples/shop/e2e/dev-hmr.mjs", $url, $copy], $root, 150);
            // Live updates through the dev Caddyfile's hub, as from a build.
            $live = runWithTimeout(
                ['node', "{$root}/examples/shop/e2e/live.mjs", $url, DemoAccount::EMAIL, DemoAccount::PASSWORD, StaffAccount::EMAIL, StaffAccount::PASSWORD],
                $root,
                150,
            );
        } finally {
            $code = $stop();
        }

        // Rendered by the dev server from source, no build anywhere: the
        // browser's entry is the dev server's, under /_vite/ on PHP's origin.
        expect($status)->toBe(200)
            ->and($body)->toContain('Sign in to Pellwick Supply')
            ->and($body)->toContain('href="/_vite/@id/__x00__virtual:vite-rsc/entry-browser"')
            ->and($body)->not->toContain('/assets/')
            // What dev needs from the policy, and only in dev.
            ->and($headers['content-security-policy'] ?? '')->toContain("'strict-dynamic' 'unsafe-eval'");

        // The dev server's own client, through FrankenPHP's port.
        expect($clientStatus)->toBe(200)
            ->and($clientHeaders['content-type'] ?? '')->toContain('javascript');

        // The error pages render in dev as anywhere.
        expect($missing)->toBe(404)
            ->and($missingBody)->toContain('Nothing lives at this address');

        // Answered only to a request naming this machine.
        expect($rebound)->toBe(421)
            ->and($reboundModule)->toBe(421)
            ->and($named)->toBe(200);

        $result = json_decode($output, true);
        expect($result)->toBeArray("the browser script failed:\n{$output}");
        \assert(\is_array($result));

        expect($result['errors'])->toBe([])
            ->and($result['violations'])->toBe([])
            // Every module from PHP's origin, the dev server's port unseen.
            ->and($result['sameOrigin'])->toBe([true])
            ->and($result['before'])->toBe('Sign in to Pellwick Supply');

        // A server component: re-rendered, its payload mounted in place.
        expect($result['server'])->toBe([
            'heading' => 'Sign in to Pellwick Supply, edited',
            'reloaded' => false,
            'typed' => 'kept@shop.test',
        ]);

        // A client component: swapped in place by Fast Refresh.
        expect($result['client'])->toBe([
            'heading' => 'Demo account, edited',
            'reloaded' => false,
            'typed' => 'kept@shop.test',
        ]);

        // An order placed in one tab shows in the customer's other tab and in
        // staff's list, refetched in place, through the hub in dev too.
        $updated = json_decode($live, true);
        expect($updated)->toBeArray("the live script failed:\n{$live}");
        \assert(\is_array($updated));
        expect($updated['errors'])->toBe([])
            ->and($updated['other']['after']['first'])->toBe($updated['number'])
            ->and($updated['other']['documents'])->toBe(0)
            ->and($updated['admin']['after']['first'])->toBe($updated['number'])
            ->and($updated['other']['live'])->toBe([
                ['path' => '/_live/subscribe', 'status' => 200],
                ['path' => '/.well-known/mercure', 'status' => 200],
            ]);

        expect($code)->toBe(0);

        // And the script put its edits back.
        expect((string) file_get_contents("{$copy}/resources/pages/shop/sign-in.tsx"))->toBe($before['page'])
            ->and((string) file_get_contents("{$copy}/resources/components/SignInForm.tsx"))->toBe($before['form']);
    } finally {
        exec('rm -rf ' . escapeshellarg($copy) . ' ' . escapeshellarg($keyDir));
    }
});
