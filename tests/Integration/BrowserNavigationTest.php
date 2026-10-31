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
use Shop\StaffAccount;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Testing\Browser\BrowserTestRun;
use Tinvar\Support\Uuid;

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
 * @param array<string, string> $env more of its environment than PATH: the
 *        test endpoint's token, for a script that calls it
 */
function runWithTimeout(array $command, string $cwd, int $seconds, array $env = []): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', ...$env]);
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
 * The served shop for one test: a pool of two renderers, a freshly seeded
 * database, and php -S on the front controller.
 *
 * A pool, as `tinvar serve` runs one: every php -S request is a process of
 * its own that starts its turn at random, so a visit's renders -- the
 * document, its payloads, its fragments -- land on either renderer, and a
 * visit that only worked when one process saw all of it would fail here.
 *
 * Under the test profile, $testProfile, it serves the test build behind the
 * browser test endpoint (ADR 0066), to a run of its own: the run's token
 * is handed to $drive, for the script it runs, and to nothing else -- the
 * server holds only its hash.
 *
 * @param \Closure(string, string, Renderer, string): void $drive given the origin, the
 *        file the shop writes its log to (SHOP_LOG), the renderers, and the
 *        test endpoint's token ('' unless $testProfile)
 * @param array<string, string> $env more of the server's environment
 */
function served(string $root, \Closure $drive, array $env = [], bool $testProfile = false): void
{
    $run = null;
    if ($testProfile) {
        $unbuilt = Shop::ensureTestBuilt();
        if ($unbuilt !== null) {
            test()->markTestSkipped($unbuilt);
        }
        $run = BrowserTestRun::prepare();
        $env = [
            ...$env,
            'TINVAR_PROFILE' => 'test',
            'TINVAR_ENV' => 'testing',
            'TINVAR_TEST_RUN' => $run['directory'],
            'TINVAR_BUILD_DIR' => Shop::testBuild(),
        ];
    }
    $renderer = Renderer::start('msgpack', 'browser-e2e', $testProfile ? Shop::testBuild() . '/rsc/index.js' : null, processes: 2);
    $keyFile = sys_get_temp_dir() . '/tinvar-e2e-' . bin2hex(random_bytes(4)) . '/app.key';
    $errorLog = (string) tempnam(sys_get_temp_dir(), 'tinvar-e2e-log-');
    // A file of its own: php -S writes its standard error without
    // appending, over whatever another writer appended.
    $appLog = (string) tempnam(sys_get_temp_dir(), 'tinvar-e2e-app-');
    // No TINVAR_APP_KEY: the app generates one into a fresh key file.
    // Every php -S request is its own process, so a deferred boundary
    // loading at all shows that they all read the one key.
    $serverEnv = [
        ...array_diff_key(getenv(), ['TINVAR_APP_KEY' => true]),
        'TINVAR_SOCKET' => $renderer->socketPath,
        'TINVAR_APP_KEY_FILE' => $keyFile,
        'SHOP_DATABASE' => Shop::database(),
        'SHOP_LOG' => $appLog,
        ...$env,
    ];
    $server = proc_open(
        // Port 0: the system picks a free one as php -S binds it, and php -S
        // names it in the line it starts with. A port picked here at random
        // could already be someone else's -- any process on the machine, not
        // only this suite -- and php -S, failing to bind it, exited only
        // after a check that it was still running had passed, so the browser
        // drove the stranger and the shop's own key was never generated.
        [PHP_BINARY, '-S', '127.0.0.1:0', "{$root}/examples/shop/public/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errorLog, 'w']],
        $pipes,
        $root,
        $serverEnv,
    );

    try {
        // php -S is up once it says so, naming the port it bound: it writes
        // that line after it is listening, and never when it could not.
        $port = null;
        $deadline = microtime(true) + 10;
        while ($port === null && proc_get_status($server)['running'] && microtime(true) < $deadline) {
            if (preg_match('#Development Server \(http://127\.0\.0\.1:(\d+)\) started#', (string) file_get_contents($errorLog), $started) === 1) {
                $port = (int) $started[1];
            } else {
                usleep(50_000);
            }
        }
        expect($port)->not->toBeNull('php -S did not start: ' . file_get_contents($errorLog));

        // And the server's environment, for a script that runs the shop's
        // own commands beside it -- its queue worker.
        $drive("http://127.0.0.1:{$port}", $appLog, $renderer, $run['token'] ?? '', $serverEnv);
    } finally {
        proc_terminate($server);
        proc_close($server);
        $renderer->stop();
        if ($run !== null) {
            BrowserTestRun::remove($run['directory']);
        }
        @unlink($errorLog);
        @unlink($appLog);
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

    // The page's modules preloaded by its Link header too -- the client entry
    // first -- under the same policy, with nothing refused (the errors above).
    expect($result['link'])->toMatch('#^</assets/index-[\w-]+\.js>; rel=modulepreload(, </assets/[\w-]+\.js>; rel=modulepreload)*$#');

    // One title, through the shop's template, on the document and after each
    // client visit; and PHP's fallback description, which no page replaces,
    // once. The document's icon throughout, and the product's canonical URL
    // only on the product, put in by a client visit and taken out by a back.
    $head = static fn (string $title, array $canonicals): array => [
        'titles' => [$title], 'descriptions' => 1, 'canonicals' => $canonicals, 'icons' => 1,
    ];
    expect($result['head'])->toBe([
        'first' => $head('Catalog · Pellwick Supply', []),
        'second' => $head("{$result['name']} · Pellwick Supply", [$result['path']]),
        'back' => $head('Catalog · Pellwick Supply', []),
    ]);

    // One document load; then the product page prefetched while the pointer
    // rested on its card -- the payload, for the build the page metadata
    // named, said to be a prefetch -- and the click answered from it, with
    // no request of its own; then back.
    expect($result['requests'])->toBe([
        ['path' => '/', 'rsc' => null, 'version' => null, 'purpose' => null],
        ['path' => $result['path'], 'rsc' => '1', 'version' => 'dev', 'purpose' => 'prefetch'],
        ['path' => '/', 'rsc' => '1', 'version' => 'dev', 'purpose' => null],
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
// ADR 0092: a tap on a product card, which no pointer rested on, so nothing
// was prefetched. The visit is instant: the header stays and the shop
// layout's loading boundary takes the catalog's place at once, under the
// catalog's address, until the product -- held back a second -- replaces it.
it('shows the layout and its loading boundary at once on an instant visit to a product', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(['node', "{$root}/examples/shop/e2e/instant.mjs", $origin], $root, 60);
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['violations'])->toBe([])
        ->and($result['during'])->toBe(['path' => '/', 'heading' => null, 'header' => true])
        ->and($result['after']['path'])->toStartWith('/products/')
        ->and($result['after']['loading'])->toBeFalse();
});

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

    // The other two scrolled in: the second page, a background partial
    // reload of the page segment merged into the table that was up, the
    // figures above it held by the browser and so left out (ADR 0020).
    expect($result['infinite'])->toBe([
        'only' => 'page.orders',
        'held' => 'orders.summary',
        'partial' => '1',
        'rows' => 12,
        'range' => 'Showing 1–12 of 12PreviousNext',
        'summary' => true,
        'sameDocument' => true,
        'newEntries' => 0,
    ]);

    // A filter replaces the list rather than adding to it, and so does
    // going back to all of them: the first page alone.
    expect($result['reset'])->toBe([
        'reset' => 'page.orders',
        'only' => 'page.orders',
        'held' => 'orders.summary',
        'url' => '?status=delivered',
        'rows' => 7,
        'range' => 'Showing 1–7 of 7PreviousNext',
        'all' => ['rows' => 10, 'range' => 'Showing 1–10 of 12PreviousNext'],
    ]);

    // Placing an order invalidated the tag the figures were kept under, so
    // the orders page after it claimed nothing and counted the new order.
    expect($result['afterOrder'])->toBe(['held' => null, 'lastPlaced' => true]);

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

    // The open order's status poll, fifteen seconds on: a background partial
    // reload of the page's own segment, merged into the page that was up --
    // the layout kept, the same document, no new history entry.
    expect($result['partial'])->toBe([
        'only' => 'page.order',
        'page' => 'shop/order',
        'status' => 200,
        'partial' => '1',
        'heading' => $result['placed']['heading'],
        'layout' => true,
        'sameDocument' => true,
        'newEntries' => 0,
    ]);

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

// "Client-held data ... is bound to (user, tenant)." A product page
// prefetched for a guest was what the router showed after signing in from
// it: the action's redirect was answered from the prefetch cache, so the
// customer was asked to sign in again until they reloaded.
it('shows a product page prefetched before signing in as the customer sees it, once signed in from it', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/sign-in-from-product.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            $root,
            60,
        );
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    // The order form, in place of the link to sign in, without a reload:
    // the redirect was followed as a client visit that asked the server.
    expect($result['errors'])->toBe([])
        ->and($result['orderForm'])->toBeTrue()
        ->and($result['signInLink'])->toBeFalse()
        ->and($result['account'])->toBeTrue()
        ->and($result['sameDocument'])->toBeTrue()
        ->and($result['visitsAfterSignIn'])->toBe([$result['path']]);
});

// "History encryption: on by default for authenticated routes, including
// remembered form state, using `crypto.subtle` and a session-scoped key ...
// logout calls `clearHistory()`." The product page's quantity is remembered
// on its history entry; for a signed-in customer the tab's storage holds it
// only sealed (ADR 0019).
it('keeps what a signed-in customer typed sealed in the tab, opens it for their session, and clears it on sign-out', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/history.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            $root,
            60,
        );
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        // A visitor who is not signed in is handed no key; the customer's
        // product page came with one.
        ->and($result['signedOut'])->toBe('undefined')
        ->and($result['keyed'])->not->toBeEmpty()
        ->and(array_unique(array_map(static fn (string $line): string => substr($line, strrpos($line, ' ') + 1), $result['keyed'])))->toBe(['key']);

    // No plaintext draft: the quantity's entry is ciphertext, and nothing is
    // kept in the clear.
    expect($result['storage'])->toBe(['sealed' => 1, 'entries' => [], 'plaintext' => false]);

    // It opens again for the same session: back to the page, and a reload --
    // whose document carried the key, which the router took out of the page
    // metadata once it had imported it, keeping only the CryptoKey.
    expect($result['back'])->toBe('7')
        ->and($result['reloadedKey'])->toBe(['served' => true, 'afterStart' => 'undefined'])
        ->and($result['reloaded'])->toBe('7');

    // Signing out wiped it from the tab.
    expect($result['afterSignOut'])->toBeNull();
});

/**
 * A copy of the seeded database in which the demo customer has $total
 * orders, the ones added older than the rest: enough for the orders list to
 * be read a page at a time past its second page. A copy, so the other tests
 * find the twelve orders they count.
 */
function databaseWithOrders(int $total): string
{
    $path = sys_get_temp_dir() . '/tinvar-e2e-orders-' . bin2hex(random_bytes(4)) . '.sqlite';
    $source = new \PDO('sqlite:' . Shop::database());
    $source->exec('VACUUM INTO ' . $source->quote($path));

    $pdo = new \PDO('sqlite:' . $path);
    $customer = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();
    $have = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $pdo->quote($customer))?->fetchColumn();
    $insert = $pdo->prepare(
        'INSERT INTO orders (id, number, customer_id, status, placed_at, subtotal_cents, shipping_cents, total_cents)'
        . " VALUES (?, ?, ?, 'delivered', ?, 1000, 500, 1500)",
    );
    for ($i = 1; $i <= $total - $have; $i++) {
        $insert->execute([Uuid::v7(), \sprintf('E2E-%04d', $i), $customer, gmdate('Y-m-d\TH:i:s\Z', time() - (340 + $i) * 86_400)]);
    }

    return $path;
}

// "Infinite scroll ... URL sync of the page param." A list read a page at a
// time, to its third page, then an order opened and back: the page arrived
// holding only the page its URL names, and the raw scroll position restored
// over it landed about a page away. The list records the row at the top of
// what the reader saw and puts them back at it, with the pages they had
// (ADR 0020).
it('puts the reader back at the order they opened, after reading the orders to the third page', function () use ($root) {
    $database = databaseWithOrders(45);
    $output = '';
    try {
        served($root, function (string $origin) use ($root, &$output): void {
            $output = runWithTimeout(
                ['node', "{$root}/examples/shop/e2e/scroll-back.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
                $root,
                90,
            );
        }, ['SHOP_DATABASE' => $database]);
    } finally {
        @unlink($database);
    }

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    // Four pages loaded, read back up into the third, the bar following.
    expect($result['errors'])->toBe([])
        ->and($result['before']['shown'])->toBe(40)
        ->and($result['before']['url'])->toBe('/orders?page=3');

    // Back, and after a reload: the same row at the same place on the
    // screen, every page the reader had shown again from the first, and the
    // bar still on the third.
    foreach (['after', 'reloaded'] as $when) {
        expect($result[$when]['top'])->not->toBeNull("the order opened is not on the page {$when}")
            ->and(abs($result[$when]['top'] - $result['before']['top']))->toBeLessThan(2.0, "{$when}: {$output}")
            ->and($result[$when]['first'])->toBe($result['before']['first'])
            ->and($result[$when]['shown'])->toBe(40)
            ->and($result[$when]['url'])->toBe('/orders?page=3');
    }

    // What the entry kept: the third page's first row, at the top, with the
    // four pages loaded -- by its place, never which order it is. The page
    // is encrypted, and history state is kept in the clear (ADR 0019); the
    // order's key stays in memory for a back, and a reload finds it by place.
    expect($result['before']['anchor'])->toMatchArray(['page' => 3, 'key' => null, 'index' => 0, 'first' => 1, 'last' => 4, 'window' => true]);
});

// "A deferred ... marked `rescue` that throws is reported to the PHP
// exception handler and omitted; the client boundary renders its `rescue`
// slot instead of failing the page." The shop is served with the panel's
// query made to throw (ShippingUpdates::failingForTest), so this is a real
// failure in PHP, carried through the renderer as an error row.
it('shows the shipping panel\'s rescue slot when its fragment fails, and logs what the slot quotes', function () use ($root) {
    $output = '';
    $log = '';
    $rendererLog = '';
    served($root, function (string $origin, string $appLog, Renderer $renderer) use ($root, &$output, &$log, &$rendererLog): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/rescue.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD],
            $root,
            90,
        );
        $log = (string) file_get_contents($appLog);
        $rendererLog = $renderer->log();
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
    // in the log, beside the exception that caused it -- one JSON line each,
    // filed under the id of the fragment request that failed (ADR 0041).
    $reference = $result['rescue']['reference'];
    $records = array_values(array_filter(
        array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", trim($log))),
        static fn (mixed $record): bool => \is_array($record) && ($record['digest'] ?? null) === $reference,
    ));
    // The panel failed twice, before and after its retry, under the same
    // digest: each time, the query's failure and the rescued subtree are
    // two lines of one request.
    $byRequest = [];
    foreach ($records as $record) {
        expect($record['request_id'] ?? '')->toMatch('/^[0-9a-f]{32}$/');
        $byRequest[$record['request_id']][] = $record['message'];
    }
    $starting = static fn (array $messages, string $prefix): bool => array_filter($messages, static fn (string $m): bool => str_starts_with($m, $prefix)) !== [];
    $both = array_filter($byRequest, static fn (array $messages): bool => $starting($messages, 'RuntimeException: Shipping updates failed on purpose')
        && $starting($messages, 'Tinvar\\Render\\RendererException'));
    expect($both)->not->toBe([], "the log:\n{$log}");

    // And the renderer logged its side of it under the same id: the spec's
    // "shared request ID in both logs".
    $renderers = array_values(array_filter(
        array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", $rendererLog)),
        static fn (mixed $line): bool => \is_array($line) && ($line['source'] ?? null) === 'renderer' && ($line['digest'] ?? null) === $reference,
    ));
    expect($renderers)->not->toBe([], "the renderer's log:\n{$rendererLog}")
        ->and($renderers[0]['message'])->toBe('fragment error')
        ->and(array_keys($byRequest))->toContain($renderers[0]['request_id'] ?? null);

    // Nothing failed but the panel. The browser logs the error row React
    // rendered for it; nothing else.
    $unexpected = array_values(array_filter(
        $result['errors'],
        static fn (string $e): bool => !str_contains($e, 'An error occurred in the Server Components render'),
    ));
    expect($unexpected)->toBe([]);
});

// The admin panel: its guard sending a signed-out visitor to sign in, staff
// let in to every customer's orders, a customer refused -- and the panel in
// the route token, so moving between it and the shop is a panel switch.
it('lets staff into the admin panel\'s orders, and refuses a customer', function () use ($root) {
    $output = '';
    served($root, function (string $origin) use ($root, &$output): void {
        $output = runWithTimeout(
            [
                'node',
                "{$root}/examples/shop/e2e/admin.mjs",
                $origin,
                StaffAccount::EMAIL,
                StaffAccount::PASSWORD,
                DemoAccount::EMAIL,
                DemoAccount::PASSWORD,
            ],
            $root,
            90,
        );
    });

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['redirected'])->toBe(['path' => '/sign-in', 'next' => '/admin/orders']);

    // Every customer's orders, the newest fifty of them, in the admin panel;
    // the catalog after it, in none.
    expect($result['admin']['heading'])->toBe('All orders')
        ->and($result['admin']['rows'])->toBe(\Shop\Pages\Admin\Orders::LIMIT)
        ->and($result['admin']['customers'])->toBeGreaterThan(1)
        ->and($result['admin']['panel'])->not->toBe([])
        ->and(array_unique($result['admin']['panel']))->toBe(['admin'])
        ->and(array_unique($result['catalogPanel']))->toBe(['']);

    // The next fifty, loaded as the end of the table came into view: a
    // partial reload of the list alone at the cursor the first page handed
    // out, each row shown once, and the bar on the page in view, by the
    // cursor it was read at (ADR 0120).
    expect($result['scrolled']['sameDocument'])->toBeTrue()
        ->and($result['scrolled']['loaded'])->toBeTrue()
        ->and($result['scrolled']['followed'])->toBeTrue()
        ->and($result['scrolled']['rows'])->toBeGreaterThan(\Shop\Pages\Admin\Orders::LIMIT)
        ->and($result['scrolled']['numbers'])->toBe($result['scrolled']['rows'])
        ->and($result['scrolled']['partials'][0]['only'] ?? null)->toBe('page.orders')
        ->and($result['scrolled']['partials'][0]['cursor'] ?? '')->not->toBe('')
        ->and($result['scrolled']['bar'])->toBe($result['scrolled']['partials'][0]['cursor']);

    // A customer holds no membership of the panel: the shop's own 403 page.
    expect($result['refused'])->toBe(['status' => 403, 'heading' => 'This page is not open to your account']);

    // The panel's StyleX rules in a sheet of its own (ADR 0109): applied on
    // its page, and never fetched by a customer's -- who loads the document's
    // stylesheet alone, with the shared rules, whatever page refused them.
    expect($result['admin']['sheets'])->toBe([['name' => 'styles-admin', 'applied' => true]])
        ->and(array_unique($result['customerSheets']))->toBe(['index']);
});

// "Uploaded files are referenced only by a server-issued upload ticket": the
// account page's two forms, in Chromium against the served shop. Each file
// goes to /_upload under the page's ticket and is saved by its action; a
// file that only claims to be an image is refused by its bytes.
it('uploads an avatar and a receipt photo from the account page, and refuses a file posing as an image', function () use ($root) {
    $storage = sys_get_temp_dir() . '/tinvar-e2e-uploads-' . bin2hex(random_bytes(4));
    $fixtures = sys_get_temp_dir() . '/tinvar-e2e-images-' . bin2hex(random_bytes(4));
    mkdir($fixtures, 0o700);
    $image = imagecreatetruecolor(4, 4);
    \assert($image !== false);
    imagepng($image, "{$fixtures}/avatar.png");
    imagejpeg($image, "{$fixtures}/receipt.jpg");

    $output = '';
    try {
        served($root, function (string $origin) use ($root, $fixtures, &$output): void {
            $output = runWithTimeout(
                ['node', "{$root}/examples/shop/e2e/uploads.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD, "{$fixtures}/avatar.png", "{$fixtures}/receipt.jpg"],
                $root,
                90,
            );
        }, ['SHOP_STORAGE' => $storage]);
    } finally {
        exec('rm -rf ' . escapeshellarg($storage) . ' ' . escapeshellarg($fixtures));
    }

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['csp'])->toBe([])
        ->and($result['redirected'])->toBe('/sign-in?next=%2Faccount')
        ->and($result['before'])->toBe(['images' => 0, 'empty' => true]);

    // Refused by what its bytes are, whatever its name and type said.
    expect($result['refused'])->toStartWith('That file is not a PNG, JPEG or WebP image');

    // Three files to /_upload, each with the CSRF header; two saved, each by
    // its own action -- the refused one never reached one. (Sign-in may be
    // sent twice: a first visit's 419 is retried with the cookie it set.)
    $saves = array_values(array_filter($result['actions'], static fn (string $a): bool => $a !== 'account.signIn'));
    expect($result['uploads'])->toBe([['csrf' => true], ['csrf' => true], ['csrf' => true]])
        ->and($saves)->toBe(['account.updateAvatar', 'account.addReceipt']);

    // Shown back as what it was sniffed as, to this session, and to no one else.
    expect($result['fetched'])->toBe(['status' => 200, 'type' => 'image/png', 'nosniff' => 'nosniff'])
        ->and($result['elsewhere'])->toBe(404)
        ->and($result['receipts'])->toBe(1);
});

// "#[Optional] ... only when named in X-Only" (ADR 0045): the product page
// sends a customer's own orders of it with no render until its button asks,
// and the button's partial reload names the field and brings back the page
// segment with them -- in the same document.
it('asks for a customer\'s orders of a product only when they press the button for them', function () use ($root) {
    // A database of its own: the uploads test leaves the shared one's demo
    // account an avatar whose file went with that test's storage.
    $database = Shop::testDatabase();
    $pdo = new \PDO('sqlite:' . $database);
    $customer = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();
    $latest = $pdo->prepare('SELECT l.product_id FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.customer_id = ? ORDER BY o.placed_at DESC LIMIT 1');
    $latest->execute([$customer]);
    $product = (string) $latest->fetchColumn();
    $orders = $pdo->prepare(
        'SELECT o.number FROM orders o JOIN order_lines l ON l.order_id = o.id WHERE o.customer_id = ? AND l.product_id = ? '
        . 'GROUP BY o.id ORDER BY o.placed_at DESC, o.id DESC LIMIT ' . \Shop\Pages\ProductPage::YOUR_ORDERS,
    );
    $orders->execute([$customer, $product]);
    $expected = $orders->fetchAll(\PDO::FETCH_COLUMN);
    // Closed before the shop opens it: a statement left open holds a read
    // lock its first write -- the page each visit records -- would wait on.
    unset($latest, $orders, $pdo);

    $output = '';
    served($root, function (string $origin) use ($root, $product, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/your-orders.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD, "/products/{$product}"],
            $root,
            60,
        );
    }, ['SHOP_DATABASE' => $database]);

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['tableBefore'])->toBe(0)
        ->and($result['xOnly'])->toBe('page.yourOrders')
        ->and($result['xPage'])->toBe('shop/product')
        ->and($expected)->not->toBe([])
        ->and(array_map('trim', $result['numbers']))->toBe($expected)
        ->and($result['buttonAfter'])->toBe(0)
        ->and($result['sameDocument'])->toBeTrue();
});

// The shop's one action that revalidates its page in place (ADR 0121): a
// customer cancels a placed order from its page. No redirect -- the same
// document, the same URL -- and the page says cancelled; the shipping
// updates below, a <Deferred> boundary, dim while the action runs and are
// fetched again for the order's tag it invalidated.
it('cancels an order from its page in place, the shipping updates dimmed and fetched again', function () use ($root) {
    $database = Shop::testDatabase();
    $pdo = new \PDO('sqlite:' . $database);
    $customer = (string) $pdo->query('SELECT id FROM customers WHERE email = ' . $pdo->quote(DemoAccount::EMAIL))?->fetchColumn();
    $order = (string) $pdo->query('SELECT id FROM orders WHERE customer_id = ' . $pdo->quote($customer) . ' ORDER BY placed_at DESC LIMIT 1')?->fetchColumn();
    // Placed just now, so nothing moves it on while the browser reads it.
    $pdo->prepare("UPDATE orders SET status = 'placed', placed_at = ? WHERE id = ?")->execute([gmdate('Y-m-d\TH:i:s\Z'), $order]);
    unset($pdo);

    $output = '';
    served($root, function (string $origin) use ($root, $order, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/cancel-order.mjs", $origin, DemoAccount::EMAIL, DemoAccount::PASSWORD, "/orders/{$order}"],
            $root,
            60,
        );
    }, ['SHOP_DATABASE' => $database]);

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['before'])->toBe(['fragments' => 1, 'status' => 'Placed'])
        ->and($result['actions'])->toBe([['action' => 'orders.cancel', 'status' => 200]])
        ->and($result['after']['status'])->toBe('Cancelled')
        ->and($result['after']['path'])->toBe("/orders/{$order}")
        ->and($result['after']['cancelButton'])->toBe(0)
        ->and($result['sameDocument'])->toBeTrue()
        // Dimmed while the action ran, then fetched once more.
        ->and($result['dimmed'])->toBeTrue()
        ->and($result['after']['fragments'])->toBe(2);
});

// The browser test endpoint, in a browser (ADR 0066): a customer seeded and
// signed in through the shop's own sessions, a session run out by the
// shop's clock and back, and a page's own script, with no token, finding
// nothing there.
it('seeds, signs in and moves the clock through the test endpoint, and to no page', function () use ($root) {
    $output = '';
    served($root, function (string $origin, string $log, Renderer $renderer, string $token) use ($root, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/test-endpoint.mjs", $origin],
            $root,
            60,
            ['TINVAR_TEST_TOKEN' => $token],
        );
    }, ['SHOP_DATABASE' => Shop::testDatabase()], testProfile: true);

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['signedIn'])->toBe(['path' => '/orders', 'heading' => 'Orders'])
        ->and($result['account'])->toBeTrue('the header names the customer signed in')
        ->and($result['offset'])->toBe(86_400)
        ->and($result['expired']['path'])->toBe('/sign-in', 'a day on, the twelve-hour session is over')
        ->and($result['resetOffset'])->toBe(0)
        ->and($result['restored'])->toBe($result['signedIn'], 'the clock moved, not the session')
        ->and($result['fromPage']['endpoint'])->toBe($result['fromPage']['nowhere'], 'a page has no token: answered as a path with no page')
        ->and($result['fromPage']['endpoint']['status'])->toBe(404)
        ->and($result['fromPage']['endpoint']['users'])->toBeFalse()
        ->and($result['wrongToken'])->toBe('404');
});

// "Switches act now" (MER-15.1): each marketing list's switch saves the
// customer's choice as it is flipped, through the action with the session's
// CSRF token, and the page drawn again shows it kept. For a customer of its
// own, seeded and signed in through the test endpoint (ADR 0066), rather
// than the demo account through the form, which other tests cover.
// The emailed links' pages, which only the framework's tests drove: each
// link asked for on its form, mailed by the shop's queue worker, opened --
// which signs nobody in -- used by its button, and refused the second time.
it('signs in by an emailed link, resets a password by one, and joins the staff by an invite', function () use ($root) {
    $mailDirectory = sys_get_temp_dir() . '/tinvar-e2e-mail-' . bin2hex(random_bytes(4));
    mkdir($mailDirectory, 0o700);
    $mailLog = "{$mailDirectory}/mail.log";
    $output = '';
    try {
        served($root, function (string $origin, string $log, Renderer $renderer, string $token, array $env) use ($root, &$output): void {
            $output = runWithTimeout(
                ['node', "{$root}/examples/shop/e2e/email-links.mjs", $origin, StaffAccount::EMAIL, StaffAccount::PASSWORD],
                $root,
                120,
                [...array_filter($env, \is_string(...)), 'TINVAR_TEST_TOKEN' => $token, 'TINVAR_E2E_PHP' => PHP_BINARY],
            );
        }, ['SHOP_DATABASE' => Shop::testDatabase(), 'SHOP_MAIL_LOG' => $mailLog], testProfile: true);
    } finally {
        @unlink($mailLog);
        @rmdir($mailDirectory);
    }

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        // Built on the canonical https origin, never the request's Host.
        ->and([$result['signIn']['built'], $result['reset']['built'], $result['invite']['built']])
        ->toBe(['https://pellwick.test', 'https://pellwick.test', 'https://pellwick.test'])
        ->and($result['signIn']['heading'])->toBe('Sign in to Pellwick Supply')
        ->and($result['signIn']['names'])->toBeTrue()
        ->and($result['signIn']['signedInByOpening'])->toBeFalse('opening the link only looks')
        ->and($result['signIn']['landed'])->toBe('/orders')
        ->and($result['signIn']['account'])->toBe('/account')
        ->and($result['signIn']['again'])->toBe("This link won't work")
        ->and($result['reset']['heading'])->toBe('Choose a new password')
        ->and($result['reset']['landed'])->toBe('/orders')
        ->and($result['reset']['again'])->toBe("This link won't work")
        ->and($result['reset']['signedInWithIt'])->not->toBe('/sign-in')
        ->and($result['invite']['listed'])->toBeTrue()
        ->and($result['invite']['heading'])->toBe("Join Pellwick Supply's staff")
        ->and($result['invite']['landed'])->toBe('/admin/orders')
        ->and($result['invite']['again'])->toBe("This invite won't work");
});

it('saves an email preference the moment its switch is flipped', function () use ($root) {
    $output = '';
    served($root, function (string $origin, string $log, Renderer $renderer, string $token) use ($root, &$output): void {
        $output = runWithTimeout(
            ['node', "{$root}/examples/shop/e2e/email-preferences.mjs", $origin],
            $root,
            60,
            ['TINVAR_TEST_TOKEN' => $token],
        );
    }, ['SHOP_DATABASE' => Shop::testDatabase()], testProfile: true);

    $result = json_decode((string) $output, true);
    expect($result)->toBeArray("the browser script failed:\n{$output}");

    expect($result['errors'])->toBe([])
        ->and($result['landed'])->toBe('/account/email', 'signed in: the page let the customer through')
        ->and($result['email'])->toMatch('/^browser-[0-9a-f]{16}@shop\.test$/')
        ->and($result['before'])->toBeFalse()
        ->and([$result['firstStatus'], $result['secondStatus']])->toBe([200, 200])
        ->and($result['afterFlip'])->toBeTrue()
        ->and($result['afterReload'])->toBeTrue()
        ->and($result['restored'])->toBeFalse();
});
