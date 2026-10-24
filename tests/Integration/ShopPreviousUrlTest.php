<?php

declare(strict_types=1);

/**
 * The shop's previous URLs: the store it keeps beside its sessions, and the
 * whole path -- the renderer included -- that records a visit in it and
 * reads it back to send a form posted without script home.
 */

namespace Tinvar\Tests\Integration\ShopPreviousUrl;

use Shop\Auth\PreviousUrls;
use Shop\Database\Migrations;
use Shop\DemoAccount;
use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;

const KEY = 'shop-previous-url-test-key-32-bytes!';

/** @return array{PreviousUrls, \PDO, \Closure(int): void} the store, its database, and a clock to move */
function store(): array
{
    $path = sys_get_temp_dir() . '/tinvar-previous-' . bin2hex(random_bytes(4)) . '.sqlite';
    $connection = PdoConnection::sqlite($path, 'shop', create: true);
    Migrations::migrate($connection);
    $owner = getmypid();
    register_shutdown_function(static function () use ($path, $owner): void {
        if (getmypid() !== $owner) {
            return;
        }
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    });

    $now = time();
    $clock = static function () use (&$now): int {
        return $now;
    };
    $move = static function (int $seconds) use (&$now): void {
        $now += $seconds;
    };

    return [new PreviousUrls(new Orm($connection, new IdentityMap('shop')), KEY, $clock), new \PDO("sqlite:{$path}"), $move];
}

/** A request carrying a session cookie this key signed. */
function from(string $cookie): Request
{
    return new Request('GET', '/', cookies: [Kernel::SESSION_COOKIE => $cookie]);
}

describe('the store', function () {
    // As `sessions` is: a copy of the table is not a list of cookies.
    it('keeps a URL per session, keyed by a hash of its id', function () {
        [$urls, $pdo] = store();
        $cookie = Kernel::newSession(KEY);

        $urls->put(from($cookie), '/products/p-1?tab=specs');

        $row = $pdo->query('SELECT session, url FROM previous_urls')->fetch(\PDO::FETCH_ASSOC);
        expect($row)->toBe(['session' => hash('sha256', (string) Kernel::sessionFrom($cookie, KEY)), 'url' => '/products/p-1?tab=specs'])
            ->and($urls->get(from($cookie)))->toBe('/products/p-1?tab=specs');
    });

    it('replaces what a session held, and keeps sessions apart', function () {
        [$urls] = store();
        $one = Kernel::newSession(KEY);
        $two = Kernel::newSession(KEY);

        $urls->put(from($one), '/');
        $urls->put(from($one), '/orders');
        $urls->put(from($two), '/sign-in');

        expect($urls->get(from($one)))->toBe('/orders')
            ->and($urls->get(from($two)))->toBe('/sign-in');
    });

    it('keeps nothing, and reads nothing, for a cookie this key did not sign', function () {
        [$urls, $pdo] = store();
        $forged = Kernel::newSession('another-key-entirely-of-32-bytes!!');

        $urls->put(from($forged), '/orders');

        expect($urls->get(from($forged)))->toBeNull()
            ->and($urls->get(new Request('GET', '/')))->toBeNull()
            ->and((int) $pdo->query('SELECT COUNT(*) FROM previous_urls')->fetchColumn())->toBe(0);
    });

    // A crawler arrives as a new session every time; a row per request
    // would be a write per request and a table of sessions nobody holds.
    it('keeps nothing for a session the request itself was issued', function () {
        [$urls, $pdo] = store();

        $urls->put(from(Kernel::newSession(KEY))->withAttribute(Kernel::SESSION_ISSUED, true), '/');

        expect((int) $pdo->query('SELECT COUNT(*) FROM previous_urls')->fetchColumn())->toBe(0);
    });

    it('forgets a URL when its session expires, and sweeps it once another visit is recorded', function () {
        [$urls, $pdo, $move] = store();
        $old = Kernel::newSession(KEY, time() - Kernel::SESSION_LIFETIME + 60);
        $urls->put(from($old), '/orders');

        $move(120);
        expect($urls->get(from(Kernel::newSession(KEY))))->toBeNull();
        $urls->put(from(Kernel::newSession(KEY)), '/');

        expect($pdo->query('SELECT url FROM previous_urls')->fetchAll(\PDO::FETCH_COLUMN))->toBe(['/']);
    });

    it('gets its table from migrating a database seeded before it', function () {
        [$urls, $pdo] = store();
        $pdo->exec('DROP TABLE previous_urls');
        $pdo->exec('DROP TABLE ' . Migrator::TABLE);
        Migrations::migrate(new PdoConnection($pdo));
        $cookie = Kernel::newSession(KEY);

        expect($urls->get(from($cookie)))->toBeNull();
        $urls->put(from($cookie), '/orders');

        expect($pdo->query('SELECT url FROM previous_urls')->fetchColumn())->toBe('/orders');
    });
});

/**
 * How the sweep each recorded visit runs finds expired rows, under the write
 * lock: by an index on expires_at. Without one it read the whole table to
 * find them -- every live session's row, on every visit, 11.7 ms at 300,000.
 *
 * @return list<string>
 */
function sweepPlan(\PDO $pdo): array
{
    $plan = $pdo->query(
        'EXPLAIN QUERY PLAN DELETE FROM previous_urls WHERE session IN '
        . '(SELECT session FROM previous_urls WHERE expires_at <= 0 LIMIT ' . PreviousUrls::SWEEP_BATCH . ')',
    )?->fetchAll(\PDO::FETCH_COLUMN, 3) ?: [];

    return array_map(strval(...), $plan);
}

describe('the sweep', function () {
    it('finds expired rows by an index, in the table the migrations make', function () {
        $connection = PdoConnection::sqlite(':memory:', 'shop');
        Migrations::migrate($connection);
        $pdo = $connection->pdo();

        expect(implode("\n", sweepPlan($pdo)))->toMatch('/USING (COVERING )?INDEX previous_urls_expires_at \(expires_at<\?\)/');
    });

    // A table made before the index was, by a store that made its own
    // table the first time it needed one, as the shop's used to.
    it('gets that index from migrating a database seeded before it', function () {
        [$urls, $pdo] = store();
        $pdo->exec('DROP INDEX previous_urls_expires_at');
        $pdo->exec('DROP TABLE ' . Migrator::TABLE);
        Migrations::migrate(new PdoConnection($pdo));

        $urls->put(from(Kernel::newSession(KEY)), '/orders');

        expect(implode("\n", sweepPlan($pdo)))->toMatch('/USING (COVERING )?INDEX previous_urls_expires_at \(expires_at<\?\)/');
    });

    // A backlog -- the shop down for a day, every session expired -- is
    // swept a batch per visit, never all at once under the write lock.
    it('deletes a batch of expired rows per visit recorded, not all of them', function () {
        [$urls, $pdo] = store();
        $insert = $pdo->prepare('INSERT INTO previous_urls VALUES (?, ?, ?)');
        for ($i = 0; $i < 250; $i++) {
            $insert->execute([hash('sha256', "gone-{$i}"), '/', time() - 60]);
        }

        $urls->put(from(Kernel::newSession(KEY)), '/orders');

        expect((int) $pdo->query('SELECT COUNT(*) FROM previous_urls WHERE expires_at <= ' . time())?->fetchColumn())
            ->toBe(250 - PreviousUrls::SWEEP_BATCH);
    });
});

/**
 * The served shop, renderer and all, with a browser of one. Skipped when the
 * shop cannot be built.
 */
describe('the shop', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            test()->markTestSkipped($unbuilt);
        }
    });

    /** @param array<string, string> $cookies */
    function get(Kernel $kernel, string $url, array &$cookies, array $headers = ['accept' => 'text/html']): Response
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        parse_str((string) $query, $parsed);
        $response = $kernel->handle(new Request('GET', $path, $headers, $parsed, [], $cookies, $query, '127.0.0.1'));
        foreach ($response->cookies() as $name => $line) {
            $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }

    function recorded(string $database, array $cookies): ?string
    {
        $statement = new \PDO("sqlite:{$database}")->prepare('SELECT url FROM previous_urls WHERE session = ?');
        $statement->execute([hash('sha256', (string) Kernel::sessionFrom($cookies[Kernel::SESSION_COOKIE] ?? null, KEY))]);
        $url = $statement->fetchColumn();

        return $url === false ? null : (string) $url;
    }

    it('records each page a session loads, and not the one a prefetch asked for', function () {
        $renderer = Renderer::start('msgpack', 'previous-url');
        $database = Shop::database();
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database);
        $cookies = [];

        try {
            // The page a visitor lands on comes with their session, so it is
            // not recorded; the pages after it are.
            expect(get($kernel, '/', $cookies)->status)->toBe(200)
                ->and(recorded($database, $cookies))->toBeNull();

            expect(get($kernel, '/sign-in?next=%2Forders', $cookies)->status)->toBe(200)
                ->and(recorded($database, $cookies))->toBe('/sign-in?next=%2Forders');

            $client = ['rsc' => '1', 'accept' => 'text/x-component', 'x-version' => 'dev'];
            expect(get($kernel, '/', $cookies, [...$client, 'purpose' => 'prefetch'])->status)->toBe(200)
                ->and(recorded($database, $cookies))->toBe('/sign-in?next=%2Forders');

            expect(get($kernel, '/', $cookies, $client)->status)->toBe(200)
                ->and(recorded($database, $cookies))->toBe('/');
        } finally {
            $renderer->stop();
        }
    });

    // Back is the Referer; a browser that sends none is sent back to the
    // page its session last loaded -- the form's own.
    it('sends a sign-in posted without script and without a Referer back to the form', function () {
        $renderer = Renderer::start('msgpack', 'previous-url-form');
        $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database());
        $cookies = [];

        try {
            get($kernel, '/', $cookies);
            get($kernel, '/sign-in?next=%2Forders', $cookies);

            $token = (string) new CsrfTokens(
                new Signer(KEY, CsrfTokens::PURPOSE),
                static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
            )->for(new Request('GET', '/', cookies: $cookies));
            $fields = ['email' => DemoAccount::EMAIL, 'password' => 'not-the-password', 'next' => '/orders', '_token' => $token];

            $response = $kernel->handle(
                new Request(
                    'POST',
                    '/_action/account.signIn',
                    ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html'],
                    [],
                    $fields,
                    [...$cookies, '__Host-XSRF-TOKEN' => $token],
                    null,
                    '127.0.0.1',
                ),
                http_build_query($fields),
            );
        } finally {
            $renderer->stop();
        }

        expect($response->status)->toBe(303)
            ->and($response->header('location'))->toBe('/sign-in?next=%2Forders');
    });
});
