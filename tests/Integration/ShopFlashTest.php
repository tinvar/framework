<?php

declare(strict_types=1);

/**
 * The shop's flashes, read by a page view only when there is one: the
 * flash cookie says one may be waiting, and anyone can send it. A page view
 * writes nothing (ADR 0050) -- and a DELETE that matches no row still takes
 * SQLite's write lock, so a forged cookie queued every page view it rode on
 * behind orders and sign-ins, and failed it when the lock was not free in
 * time.
 */

namespace Tinvar\Tests\Integration\ShopFlash;

use Shop\Auth\Flashes;
use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Http\Flash;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Routing\LoadedRoute;

const KEY = 'shop-flash-test-key-of-thirty-two-b';

/**
 * A document load of the sign-in page, drawn by a stand-in for the renderer.
 *
 * @param array<string, string> $cookies
 */
function page(Kernel $kernel, array $cookies): Response
{
    return $kernel->handleWith(
        new Request('GET', '/sign-in', ['accept' => 'text/html'], [], [], $cookies, null, '127.0.0.1'),
        static fn (LoadedRoute $loaded, Request $passed): Response => Response::make(200, 'page'),
    );
}

it('serves a page view carrying the flash cookie, with nothing waiting, while another writer holds the lock', function () {
    $database = Shop::testDatabase();
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-flash-none.sock', key: KEY, database: $database);
    // Short, so a page view that waits for the lock fails in 50 ms rather
    // than the five seconds the shop waits.
    $kernel->orm->connection()->pdo()->exec('PRAGMA busy_timeout = 50');
    $session = Kernel::newSession(KEY);

    // Another worker placing an order, say.
    $writer = new \PDO("sqlite:{$database}");
    $writer->exec('BEGIN IMMEDIATE');

    try {
        $plain = page($kernel, [Kernel::SESSION_COOKIE => $session]);
        $flagged = page($kernel, [Kernel::SESSION_COOKIE => $session, Flash::COOKIE => '1']);
    } finally {
        $writer->exec('ROLLBACK');
    }

    expect($plain->status)->toBe(200)
        ->and($flagged->status)->toBe(200);
});

it('runs no write statement for a page view whose session has no flash waiting, and takes one that is', function () {
    $database = Shop::testDatabase();
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-flash-none.sock', key: KEY, database: $database);
    $session = Kernel::newSession(KEY);
    $pdo = $kernel->orm->connection()->pdo();
    \assert($pdo instanceof \Pdo\Sqlite);
    $writes = [];
    $pdo->setAuthorizer(static function (int $action, ?string $table) use (&$writes): int {
        // SQLITE_DELETE, SQLITE_INSERT, SQLITE_UPDATE
        if (\in_array($action, [9, 18, 23], true)) {
            $writes[] = "{$action}:{$table}";
        }

        return \Pdo\Sqlite::OK;
    });

    try {
        page($kernel, [Kernel::SESSION_COOKIE => $session, Flash::COOKIE => '1']);
    } finally {
        $pdo->setAuthorizer(null);
    }
    expect($writes)->toBe([]);

    // One that is waiting is still read once, and gone.
    $flashes = new Flashes($kernel->orm, KEY);
    $request = new Request('GET', '/', cookies: [Kernel::SESSION_COOKIE => $session]);
    $flashes->put($request, ['flash' => ['message' => 'Saved']]);

    expect($flashes->pull($request))->toBe(['flash' => ['message' => 'Saved']])
        ->and($flashes->pull($request))->toBe([]);
});

// A browser form post that fails CSRF -- from anyone, with no cookie at all
// -- is sent back with "page expired" in the flash cookie: nothing in the
// table, so nothing anybody can make the one writer do.
it('keeps a page expired out of the table', function () {
    $database = Shop::testDatabase();
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-flash-none.sock', key: KEY, database: $database);
    $watcher = new \PDO("sqlite:{$database}");
    $count = static fn (): int => (int) $watcher->query('SELECT COUNT(*) FROM flashes')?->fetchColumn();
    $before = $count();

    $response = $kernel->handle(new Request(
        'POST',
        '/_action/account.signIn',
        ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html'],
        [],
        ['email' => 'someone@example.com'],
        [],
        null,
        '198.51.100.9',
    ), 'email=someone%40example.com');

    expect($response->status)->toBe(303)
        ->and($response->cookies()[Flash::COOKIE] ?? '')->toStartWith(Flash::COOKIE . '=' . Flash::expired('account.signIn') . ';')
        ->and($count())->toBe($before);
});
