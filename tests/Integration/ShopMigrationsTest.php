<?php

declare(strict_types=1);

/**
 * The shop's schema, as its migrations make it: from nothing, over a
 * database built before there were migrations, through `bin/tinvar` and
 * the seed script -- and the shop running on what they made.
 */

namespace Tinvar\Tests\Integration\ShopMigrations;

use Shop\Database\Migrations;
use Shop\Database\Seeder;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Models\OrderLine;
use Shop\Models\Product;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;

const KEY = 'shop-migrations-test-key-32-bytes!!!';

const TABLES = [
    'auth_epochs', 'customers', 'failed_jobs', 'flashes', 'jobs', 'order_lines', 'orders', 'previous_urls', 'products',
    'schedule_runs', 'sessions', 'staff', 'tinvar_migrations',
];

/** A database path in a directory of its own, removed when the process ends. */
function database(): string
{
    $directory = PrivateDirectory::create('tinvar-shopmig');
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            PrivateDirectory::remove($directory);
        }
    });

    return "{$directory}/shop.sqlite";
}

/** @return list<string> */
function tables(PdoConnection $connection): array
{
    return new SqliteDialect()->tables($connection);
}

/**
 * Runs a command in a process of its own.
 *
 * @param list<string> $command
 * @return array{int, string, string} its exit status, standard output and standard error
 */
function run(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 4));
    \assert(\is_resource($process));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

it('makes every table the shop uses, and leaves nothing the models describe for make:migration', function () {
    $connection = PdoConnection::sqlite(database(), create: true);

    $ran = Migrations::migrate($connection);

    expect($ran)->toBe([
        '2026_10_24_000001_create_catalog_and_orders',
        '2026_10_24_000002_create_sessions_flashes_and_previous_urls',
        '2026_10_24_000003_create_auth_epochs_and_staff',
        '2026_10_24_000004_create_jobs_and_schedule_runs',
    ])
        ->and(tables($connection))->toBe(TABLES)
        ->and(new MigrationGenerator($connection, new SqliteDialect())->generate([Product::class, Customer::class, Order::class, OrderLine::class], '2026-10-24'))->toBeNull()
        // The rest of the schema's rules, which the models do not carry.
        ->and(fn () => $connection->execute("INSERT INTO products VALUES ('p', 's', 'n', 'x', 'y', 'c', 0, 1)"))->toThrow(\PDOException::class, 'CHECK constraint failed');
});

it('rolls all the way back to nothing, and up again', function () {
    $connection = PdoConnection::sqlite(database(), create: true);
    $migrator = Migrations::migrator($connection);
    $migrator->migrate();

    expect($migrator->rollback())->toHaveCount(4)
        ->and(tables($connection))->toBe(['tinvar_migrations'])
        ->and($migrator->migrate())->toHaveCount(4)
        ->and(tables($connection))->toBe(TABLES);
});

// What `ensureTable()` used to do for flashes, previous URLs, epochs and
// staff, and schema.sql for the rest: a database built before the shop had
// migrations is adopted as it stands, given what it lacks, and keeps its rows.
it('adopts a database built before the shop had migrations, and keeps what is in it', function () {
    $connection = PdoConnection::sqlite(database(), create: true);
    $connection->execute('CREATE TABLE products (id TEXT PRIMARY KEY, sku TEXT NOT NULL UNIQUE, name TEXT NOT NULL, summary TEXT NOT NULL, '
        . 'description TEXT NOT NULL, category TEXT NOT NULL, price_cents INTEGER NOT NULL, stock INTEGER NOT NULL)');
    $connection->execute('CREATE TABLE customers (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, name TEXT NOT NULL, '
        . 'password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');
    $connection->execute('CREATE TABLE orders (id TEXT PRIMARY KEY, number TEXT NOT NULL UNIQUE, customer_id TEXT NOT NULL REFERENCES customers (id), '
        . 'status TEXT NOT NULL, placed_at TEXT NOT NULL, subtotal_cents INTEGER NOT NULL, shipping_cents INTEGER NOT NULL, total_cents INTEGER NOT NULL)');
    $connection->execute('CREATE TABLE order_lines (id TEXT PRIMARY KEY, order_id TEXT NOT NULL REFERENCES orders (id) ON DELETE CASCADE, '
        . 'product_id TEXT NOT NULL REFERENCES products (id), position INTEGER NOT NULL, product_name TEXT NOT NULL, product_sku TEXT NOT NULL, '
        . 'unit_price_cents INTEGER NOT NULL, quantity INTEGER NOT NULL)');
    $connection->execute('CREATE TABLE sessions (id TEXT PRIMARY KEY, customer_id TEXT REFERENCES customers (id) ON DELETE CASCADE, '
        . 'created_at INTEGER NOT NULL, last_seen_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
    // As PreviousUrls made it, before it made the index too.
    $connection->execute('CREATE TABLE previous_urls (session TEXT PRIMARY KEY, url TEXT NOT NULL, expires_at INTEGER NOT NULL)');
    $connection->execute("INSERT INTO customers VALUES ('c1', 'ada@shop.test', 'Ada', 'x', '2026-01-01T00:00:00Z')");

    $ran = Migrations::migrate($connection);

    expect($ran)->toHaveCount(4)
        ->and(tables($connection))->toBe(TABLES)
        ->and($connection->select('SELECT name FROM customers'))->toBe([['name' => 'Ada']])
        ->and(array_column(new SqliteDialect()->indexes($connection, 'previous_urls'), 'name'))->toContain('previous_urls_expires_at')
        ->and(Migrations::migrator($connection)->pending())->toBe([]);
});

it('runs on what its migrations make: signing in, the epoch moved, and the admin panel asking staff', function () {
    $database = database();
    new Seeder(PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
    $kernel = Kernel::fromEnvironment('/tmp/tinvar-shop-migrations-none.sock', key: KEY, database: $database);
    $cookies = [];
    $keep = static function (\Tinvar\Http\Response $response) use (&$cookies): \Tinvar\Http\Response {
        foreach ($response->cookies() as $name => $line) {
            $cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    };

    $keep($kernel->handle(new Request('GET', '/sign-in', ['accept' => 'text/html'], [], [], $cookies)));
    $token = (string) new CsrfTokens(
        new Signer(KEY, CsrfTokens::PURPOSE),
        static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
    )->for(new Request('GET', '/', [], [], [], $cookies));
    $signIn = $keep($kernel->handle(
        new Request('POST', '/_action/account.signIn', ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token], [], [], [...$cookies, '__Host-XSRF-TOKEN' => $token]),
        json_encode(['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD, 'next' => '/'], JSON_THROW_ON_ERROR),
    ));
    $admin = $kernel->handle(new Request('GET', '/admin/orders', ['accept' => 'text/html'], [], [], $cookies));
    $connection = PdoConnection::sqlite($database);

    expect($signIn->status)->toBeLessThan(400)
        ->and($connection->select('SELECT COUNT(*) AS n FROM sessions WHERE customer_id IS NOT NULL'))->toBe([['n' => 1]])
        ->and($connection->select('SELECT epoch FROM auth_epochs'))->toBe([['epoch' => 1]])
        // The membership is asked of `staff`, and a customer has none.
        ->and($admin->status)->toBe(403);
});

describe('from the command line', function () {
    // Two deploys at once: one migrates, the other waits its turn and finds
    // nothing left to do. Neither fails, and nothing runs twice.
    it('migrates the database it is pointed at, two runs at once taking turns', function () {
        $database = database();
        // Made first, as a deploy finds it: two processes creating one file
        // at the same instant race over its journal mode before either
        // reaches the migration lock.
        PdoConnection::sqlite($database, create: true);
        $command = [PHP_BINARY, 'bin/tinvar', 'migrate', "--database={$database}"];
        $first = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $firstPipes, \dirname(__DIR__, 4));
        [$status, $out, $err] = run($command);
        \assert(\is_resource($first));
        $firstOut = (string) stream_get_contents($firstPipes[1]);
        $firstErr = (string) stream_get_contents($firstPipes[2]);
        $firstStatus = proc_close($first);
        $connection = PdoConnection::sqlite($database);

        expect([$firstStatus, $status])->toBe([0, 0], "{$firstErr}\n{$err}")
            ->and([$firstOut, $out])->toContain("Nothing to migrate.\n")
            ->and(implode('', [$firstOut, $out]))->toContain('Ran 4 migration(s).')
            ->and($connection->select('SELECT COUNT(*) AS n, MAX(batch) AS batch FROM ' . Migrator::TABLE))->toBe([['n' => 4, 'batch' => 1]])
            ->and(file_exists("{$database}-migrate.lock"))->toBeFalse();
    });

    it('says where each migration stands, and refuses a database that is not there', function () {
        $database = database();
        [$missing, , $missingErr] = run([PHP_BINARY, 'bin/tinvar', 'migrate:status', "--database={$database}"]);
        $leftNothing = !file_exists($database);
        run([PHP_BINARY, 'bin/tinvar', 'migrate', "--database={$database}"]);
        [$status, $out] = run([PHP_BINARY, 'bin/tinvar', 'migrate:status', "--database={$database}"]);

        expect($missing)->toBe(1)
            ->and($missingErr)->toBe("No database at {$database}; `tinvar migrate` makes one.\n")
            ->and($leftNothing)->toBeTrue()
            ->and($status)->toBe(0)
            ->and($out)->toContain('yes  1      2026_10_24_000003_create_auth_epochs_and_staff');
    });

    it('builds a database with the seed script, which migrates and then seeds', function () {
        $database = database();

        [$status, $out, $err] = run(['examples/shop/bin/seed', '--customers=2', "--database={$database}"]);
        $connection = PdoConnection::sqlite($database);

        expect($status)->toBe(0, $err)
            ->and($err)->toContain("Migrated {$database}: 4 migration(s).")
            ->and($out)->toContain('24 products, 3 customers')
            ->and(Migrations::migrator($connection)->pending())->toBe([])
            ->and($connection->select('SELECT COUNT(*) AS n FROM staff'))->toBe([['n' => 1]]);
    });
});
