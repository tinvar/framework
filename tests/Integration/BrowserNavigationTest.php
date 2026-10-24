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
 * metadata, a text/x-component payload, a mount that commits -- and where a
 * person's whole visit runs end to end: sign in, orders, an order, a
 * deferred boundary, an action, sign out.
 *
 * Skipped when its prerequisites are missing: the JavaScript dependencies
 * the build needs, or Playwright's Chromium.
 */

namespace Tinvar\Tests\Integration\BrowserNavigation;

use Shop\DemoAccount;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;

$root = \dirname(__DIR__, 4);

beforeAll(function () use ($root) {
    if (!is_file("{$root}/node_modules/playwright/package.json")) {
        test()->markTestSkipped('Playwright is not installed.');
    }
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
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
 * The served shop for one test: a renderer, a freshly seeded database, and
 * php -S on the front controller.
 *
 * @param \Closure(string, string): void $drive given the origin, and the file
 *        php -S writes its error log to
 * @param array<string, string> $env more of the server's environment
 */
function served(string $root, \Closure $drive, array $env = []): void
{
    $renderer = Renderer::start('msgpack', 'browser-e2e');
    $port = 18_000 + random_int(0, 999);
    $keyFile = sys_get_temp_dir() . '/tinvar-e2e-' . bin2hex(random_bytes(4)) . '/app.key';
    $errorLog = (string) tempnam(sys_get_temp_dir(), 'tinvar-e2e-log-');
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errorLog, 'w']],
        $pipes,
        $root,
        // No TINVAR_APP_KEY: the app generates one into a fresh key file.
        // Every php -S request is its own process, so a deferred boundary
        // loading at all shows that they all read the one key.
        [
            ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
            'TINVAR_SOCKET' => $renderer->socketPath,
            'TINVAR_APP_KEY_FILE' => $keyFile,
            'SHOP_DATABASE' => Shop::database(),
            ...$env,
        ],
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

        $drive("http://127.0.0.1:{$port}", $errorLog);
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
        @unlink($errorLog);
        $generated = is_file($keyFile);
        if ($generated) {
            unlink($keyFile);
        }
        foreach (glob(\dirname($keyFile) . '/{,.}*', GLOB_BRACE) ?: [] as $leftover) {
            if (is_file($leftover)) {
                unlink($leftover);
            }
        }
        if (is_dir(\dirname($keyFile))) {
            rmdir(\dirname($keyFile));
        }
    }

    // After the finally, so a failure above is reported as itself rather
    // than as a missing key.
    expect($generated)->toBeTrue('no application key was generated');
}

it('navigates from the catalog to a product and back, through the RSC payload', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(['node', "{$root}/examples/shop/e2e/navigation.mjs", $origin], $root, 60);
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['first'])->toBe('Catalog')
        ->and($result['second'])->toBe($result['name'])
        ->and($result['cards'])->toBe(24)
        ->and($result['availability'])->toBe(24, 'every card awaited its availability query')
        ->and($result['reloaded'])->toBe(1, 'one document load; the rest were client visits');

    // One document load, then a client navigation that asked for the
    // payload and named the build the page metadata gave it, then back.
    expect($result['requests'])->toBe([
        ['path' => '/', 'rsc' => null, 'version' => null],
        ['path' => $result['path'], 'rsc' => '1', 'version' => 'dev'],
        ['path' => '/', 'rsc' => '1', 'version' => 'dev'],
    ]);

    // The page ran under its Content-Security-Policy -- hydrated, and
    // navigated -- with nothing blocked, and every script carried the nonce.
    expect($result['csp'] ?? null)->toBe([
        'strictDynamic' => true,
        'nonce' => true,
        'unnonced' => [],
        'violations' => [],
    ]);

    // StyleX's stylesheet was linked and applied, under that same policy.
    expect($result['styled'])->toBe(['stylesheets' => true, 'inter' => true]);

    // The document load issues the CSRF cookie -- readable by script, which
    // copies it into the header -- and a POST carrying it gets past the
    // check to authentication, which refuses a visitor who is signed out.
    expect($result['csrf'] ?? null)->toBe([
        'path' => '/',
        'secure' => true,
        'httpOnly' => false,
        'sameSite' => 'Lax',
        'readable' => true,
        'withToken' => 401,
        'withoutToken' => 419,
    ]);
});

// A customer's whole visit: redirected to sign in, a wrong password, the
// right one, the orders, one order with its deferred shipping updates, a
// new order, and signing out.
it('signs in, reads orders and an order, places an order and signs out', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/account.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            $root,
            90,
        );
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['redirected'])->toBe(['path' => '/sign-in', 'next' => '/orders'])
        ->and($result['wrong'])->toBe(\Shop\Actions\AccountActions::NO_MATCH);

    // Sign-in and sign-out each issue a new session id.
    expect($result['rotated'])->toBe(['onSignIn' => true, 'onSignOut' => true]);

    // The demo account has twelve orders: ten on the first page.
    expect($result['orders'])->toBe([
        'heading' => 'Orders',
        'rows' => 10,
        'range' => 'Showing 1–10 of 12PreviousNext',
        'account' => true,
    ]);

    // The order page rendered without its shipping updates, which loaded
    // from /_fragment/ only once scrolled to. The order placed later has
    // one line, so its panel starts within the boundary's buffer of the
    // fold and loads without a scroll: the second request.
    expect($result['order']['heading'])->toBe("Order {$result['order']['number']}")
        ->and($result['order']['lines'])->toBeGreaterThan(0)
        ->and($result['order']['below'])->toBeGreaterThan(200, 'the panel starts past its buffer below the fold')
        ->and($result['order']['requestedBeforeScroll'])->toBe(0)
        ->and($result['order']['shipping'])->toBeTrue()
        ->and($result['fragments'])->toBe(['1', '1']);

    expect($result['actions'])->toBe(['account.signIn', 'account.signIn', 'orders.place', 'account.signOut'])
        ->and($result['placed']['heading'])->toStartWith('Order PW-')
        ->and($result['placed']['lines'])->toBe(1)
        ->and($result['placed']['quantity'])->toBe('2')
        ->and($result['afterSignOut'])->toBe('/sign-in');

    expect($result['csp'])->toBe(['nonced' => true, 'violations' => []]);

    // The session cookie round-tripped with the attributes the spec's cookie
    // rule requires.
    expect($result['session'] ?? null)->toBe([
        'path' => '/',
        'secure' => true,
        'httpOnly' => true,
        'sameSite' => 'Lax',
    ]);
});

// "A deferred ... marked `rescue` that throws is reported to the PHP
// exception handler and omitted; the client boundary renders its `rescue`
// slot instead of failing the page." The shop is served with the panel's
// query made to throw (ShippingUpdates::failingForTest), so this is a real
// failure in PHP, carried through the renderer as an error row.
it('shows the shipping panel\'s rescue slot when its fragment fails, and logs what the slot quotes', function () use ($root) {
    $output = '';
    $log = '';
    served($root, function (string $origin, string $errorLog) use ($root, &$output, &$log): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/rescue.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            $root,
            90,
        );
        $log = (string) file_get_contents($errorLog);
    }, ['SHOP_TEST_FAIL' => 'shipping-updates']);

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    // The slot, in place of the panel, with the rest of the order intact.
    expect($result['rescue']['text'])->toContain("Couldn't load shipping updates")
        ->toContain('The order above is current')
        ->toContain('Try again')
        ->and($result['rescue']['marked'])->toBeTrue()
        ->and($result['rescue']['lines'])->toBeGreaterThan(0)
        ->and($result['rescue']['reference'])->toMatch('/^[A-Za-z0-9_-]{8,64}$/');

    // The fragment answered: a payload carrying an error row, not a failed
    // page. Once rescued it waited for the slot's button, then asked once.
    expect($result['fragments'])->toBe([200, 200])
        ->and($result['retried'])->toBe(['before' => 1, 'after' => 2, 'stillRescued' => true]);

    // Reported to the exception handler: the reference the visitor sees is
    // in the log, beside the exception that caused it.
    $reference = $result['rescue']['reference'];
    expect($log)->toContain("[shop] {$reference} RuntimeException: Shipping updates failed on purpose")
        ->and($log)->toContain("[shop] {$reference} Tinvar\\Render\\RendererException");

    // Nothing failed but the panel. The browser logs the error row React
    // rendered for it; nothing else.
    $unexpected = array_values(array_filter(
        $result['errors'],
        static fn (string $e): bool => !str_contains($e, 'An error occurred in the Server Components render'),
    ));
    expect($unexpected)->toBe([]);
});
