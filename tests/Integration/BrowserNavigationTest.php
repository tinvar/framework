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
 * @param \Closure(string): void $drive given the origin
 */
function served(string $root, \Closure $drive): void
{
    $renderer = Renderer::start('msgpack', 'browser-e2e');
    $port = 18_000 + random_int(0, 999);
    $keyFile = sys_get_temp_dir() . '/tinvar-e2e-' . bin2hex(random_bytes(4)) . '/app.key';
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
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

        $drive("http://127.0.0.1:{$port}");
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
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

    // The email checked on blur against the action's own rules, which refuse
    // an address at an IP that the form's own check lets through -- and
    // checked again, and passed, once corrected. Validate-only: the action
    // never ran for either.
    expect($result['live'])->toBe('Enter a full email address, like name@example.com.')
        ->and($result['precognition'])->toBe(['account.signIn:email', 'account.signIn:email']);

    // With JavaScript off, the same form is an ordinary post: sent back with
    // the error on its field and the email put back -- never the password --
    // and on to the orders once it is right.
    expect($result['withoutScript'])->toBe([
        'back' => '/sign-in?next=%2Forders',
        'error' => \Shop\Actions\AccountActions::NO_MATCH,
        'email' => DemoAccount::EMAIL,
        'password' => '',
        'signedIn' => '/orders',
        'heading' => 'Orders',
    ]);

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
    // from /_fragment/ only once scrolled to.
    expect($result['order']['heading'])->toBe("Order {$result['order']['number']}")
        ->and($result['order']['lines'])->toBeGreaterThan(0)
        ->and($result['order']['requestedBeforeScroll'])->toBe(0)
        ->and($result['order']['shipping'])->toBeTrue()
        ->and($result['fragments'])->toBe(['1']);

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
