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
use Shop\Models\Receipt;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Request;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Support\DevMode;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;

const KEY = 'shop-migrations-test-key-32-bytes!!!';

const TABLES = [
    'auth_epochs', 'customers', 'email_links', 'failed_jobs', 'flashes', 'handoff_nonces', 'job_batch_ends', 'job_batches', 'job_locks', 'job_rates', 'jobs',
    'marketing_opt_in_sends', 'marketing_opt_ins', 'marketing_preferences', 'newsletter_issues', 'order_lines', 'order_numbers', 'orders', 'products', 'receipts', 'schedule_runs', 'sessions', 'spent_tokens', 'staff',
    'tinvar_build_history', 'tinvar_migrations', 'transfers', 'uploads', 'webhook_deliveries', 'webhook_events',
    'workflow_signals', 'workflows',
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
 * Runs a command in a process of its own, with TINVAR_ENV=testing -- what
 * lets db:seed and migrate:fresh run -- and none of the shell's own
 * production markers.
 *
 * @param list<string> $command
 * @param array<string, string|null> $environment laid over this process's; null takes a variable out
 * @return array{int, string, string} its exit status, standard output and standard error
 */
function run(array $command, array $environment = []): array
{
    $environment = array_filter(
        [...array_diff_key(getenv(), array_flip(DevMode::PRODUCTION_MARKERS)), 'TINVAR_ENV' => 'testing', ...$environment],
        \is_string(...),
    );
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 4), $environment);
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
        '2026_10_24_000005_create_webhook_deliveries',
        '2026_10_24_000006_bind_sessions_to_hosts_and_keep_handoff_codes',
        '2026_10_24_000007_create_uploads_and_receipts',
        '2026_10_24_000008_create_build_history',
        '2026_10_24_000009_count_waiting_uploads_by_owner',
        '2026_10_25_000010_count_order_numbers_by_day',
        '2026_10_25_000011_drop_previous_urls',
        '2026_10_25_000012_create_marketing_preferences',
        '2026_10_27_000013_create_transfers',
        '2026_10_27_000014_create_email_links',
        '2026_10_29_000100_create_job_coordination',
        '2026_10_29_000101_count_import_progress',
        '2026_10_29_000102_create_webhook_events',
        '2026_10_29_000201_keep_handoff_redemptions_on_sessions',
        '2026_10_30_000110_index_webhook_deliveries_by_age',
        '2026_10_30_000111_create_marketing_opt_ins',
        '2026_10_30_000112_create_newsletter_issues',
        '2026_10_30_000113_index_uploads_by_key',
        '2026_10_30_000119_create_workflows_and_spent_tokens',
        '2026_10_31_000120_create_marketing_opt_in_sends',
        '2026_10_31_000121_index_orders_by_status_and_placed_at',
        '2026_10_31_000122_index_nonces_by_expiry',
        '2026_10_31_000123_beat_transfers',
        '2026_10_31_000130_write_order_and_account_times_as_the_orm_stores_them',
    ])
        ->and(tables($connection))->toBe(TABLES)
        ->and(($generator = new MigrationGenerator($connection, new SqliteDialect()))->generate([Product::class, Customer::class, Order::class, OrderLine::class, Receipt::class], '2026-10-24'))->toBeNull()
        // A line's and a receipt's keys cascade, as the models say (ADR 0122);
        // the tables no model maps are named, and none is the models'.
        ->and(implode("\n", $generator->warnings))->not->toContain('is deleted with')
        ->and(implode("\n", $generator->warnings))->toContain('The database has tables no model maps: ')
        ->and(implode("\n", $generator->warnings))->not->toContain(' order_lines,')
        // The rest of the schema's rules, which the models do not carry.
        ->and(fn () => $connection->execute("INSERT INTO products VALUES ('p', 's', 'n', 'x', 'y', 'c', 0, 1)"))->toThrow(\PDOException::class, 'CHECK constraint failed');
});

it('rolls all the way back to nothing, and up again', function () {
    $connection = PdoConnection::sqlite(database(), create: true);
    $migrator = Migrations::migrator($connection);
    $migrator->migrate();

    expect($migrator->rollback())->toHaveCount(28)
        ->and(tables($connection))->toBe(['tinvar_migrations'])
        ->and($migrator->migrate())->toHaveCount(28)
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
    // As PreviousUrls made it when it kept the previous URL in a table, which
    // a migration has since dropped (ADR 0050).
    $connection->execute('CREATE TABLE previous_urls (session TEXT PRIMARY KEY, url TEXT NOT NULL, expires_at INTEGER NOT NULL)');
    $connection->execute("INSERT INTO customers VALUES ('c1', 'ada@shop.test', 'Ada', 'x', '2026-01-01T00:00:00Z')");

    $ran = Migrations::migrate($connection);

    expect($ran)->toHaveCount(28)
        ->and(tables($connection))->toBe(TABLES)
        ->and($connection->select('SELECT name FROM customers'))->toBe([['name' => 'Ada']])
        // The account's time, written to the second as the shop wrote it,
        // is the form the ORM writes now: the same instant, to the microsecond.
        ->and($connection->select('SELECT created_at FROM customers'))->toBe([['created_at' => '2026-01-01T00:00:00.000000Z']])
        ->and(Migrations::migrator($connection)->pending())->toBe([]);
});

// Order, account and receipt times became CarbonImmutable, which the ORM
// writes at one width, to the microsecond (ADR 0145): the rows written to
// the second before sort among those, and compare with what the ORM binds.
it('rewrites the times the shop wrote to the second as the ORM writes them, and back', function () {
    $connection = PdoConnection::sqlite(database(), create: true);
    $migrator = Migrations::migrator($connection);
    $migrator->migrate();
    $migrator->rollback(1);
    $connection->execute("INSERT INTO customers (id, email, name, password_hash, created_at) VALUES ('c1', 'ada@shop.test', 'Ada', 'x', '2026-01-01T09:30:00Z')");
    $connection->execute("INSERT INTO customers (id, email, name, password_hash, created_at) VALUES ('c2', 'bo@shop.test', 'Bo', 'x', '2026-01-01T09:30:00.250000Z')");
    $connection->execute("INSERT INTO orders (id, number, customer_id, status, placed_at, subtotal_cents, shipping_cents, total_cents) VALUES ('o1', 'PW-1', 'c1', 'placed', '2026-01-02T10:00:00Z', 1, 0, 1)");

    $migrator->migrate();
    $up = [$connection->select('SELECT created_at FROM customers ORDER BY created_at'), $connection->select('SELECT placed_at FROM orders')];
    $migrator->rollback(1);
    $down = $connection->select('SELECT created_at FROM customers ORDER BY created_at');
    $migrator->migrate();

    expect($up)->toBe([
        [['created_at' => '2026-01-01T09:30:00.000000Z'], ['created_at' => '2026-01-01T09:30:00.250000Z']],
        [['placed_at' => '2026-01-02T10:00:00.000000Z']],
    ])
        ->and($down)->toBe([['created_at' => '2026-01-01T09:30:00Z'], ['created_at' => '2026-01-01T09:30:00Z']])
        ->and(Customer::query(new \Tinvar\Orm\Orm($connection, new \Tinvar\Orm\IdentityMap('shop')))->orderBy('createdAt')->fetch()[0]->createdAt->toIso8601ZuluString())
        ->toBe('2026-01-01T09:30:00Z');
});

// The uploads table as the uploads migration made it before a waiting
// upload recorded its owner: the cap counted the user binding, epoch and
// all, so a sign-in started it over.
it('gives an uploads table made before uploads had an owner one, counting by it', function () {
    $connection = PdoConnection::sqlite(database(), create: true);
    $migrator = Migrations::migrator($connection);
    $migrator->migrate();
    $migrator->rollback(20);

    $columns = array_column($connection->select('SELECT name FROM pragma_table_info(?)', ['uploads']), 'name');
    $owner = array_values(array_filter(new SqliteDialect()->indexes($connection, 'uploads'), static fn (array $i): bool => $i['name'] === 'uploads_owner'));
    expect($columns)->not->toContain('owner_hash')
        ->and($owner[0]['columns'] ?? null)->toBe(['auth_hash']);

    expect($migrator->migrate())->toBe([
        '2026_10_24_000009_count_waiting_uploads_by_owner',
        '2026_10_25_000010_count_order_numbers_by_day',
        '2026_10_25_000011_drop_previous_urls',
        '2026_10_25_000012_create_marketing_preferences',
        '2026_10_27_000013_create_transfers',
        '2026_10_27_000014_create_email_links',
        '2026_10_29_000100_create_job_coordination',
        '2026_10_29_000101_count_import_progress',
        '2026_10_29_000102_create_webhook_events',
        '2026_10_29_000201_keep_handoff_redemptions_on_sessions',
        '2026_10_30_000110_index_webhook_deliveries_by_age',
        '2026_10_30_000111_create_marketing_opt_ins',
        '2026_10_30_000112_create_newsletter_issues',
        '2026_10_30_000113_index_uploads_by_key',
        '2026_10_30_000119_create_workflows_and_spent_tokens',
        '2026_10_31_000120_create_marketing_opt_in_sends',
        '2026_10_31_000121_index_orders_by_status_and_placed_at',
        '2026_10_31_000122_index_nonces_by_expiry',
        '2026_10_31_000123_beat_transfers',
        '2026_10_31_000130_write_order_and_account_times_as_the_orm_stores_them',
    ]);

    $columns = array_column($connection->select('SELECT name FROM pragma_table_info(?)', ['uploads']), 'name');
    $owner = array_values(array_filter(new SqliteDialect()->indexes($connection, 'uploads'), static fn (array $i): bool => $i['name'] === 'uploads_owner'));
    expect($columns)->toContain('owner_hash')
        ->and($owner[0]['columns'] ?? null)->toBe(['owner_hash']);
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
    // Two deploys at once, onto a database neither finds there: both create
    // it and switch it to WAL, one migrates, and the other waits its turn
    // and finds nothing left to do. Neither fails, and nothing runs twice.
    it('migrates the database it is pointed at, two runs at once taking turns', function () {
        $database = database();
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
            ->and(implode('', [$firstOut, $out]))->toContain('Ran 28 migration(s).')
            ->and($connection->select('SELECT COUNT(*) AS n, MAX(batch) AS batch FROM ' . Migrator::TABLE))->toBe([['n' => 28, 'batch' => 1]])
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

    it('builds a database with the seed script, which is db:seed: it migrates and then seeds', function () {
        $database = database();

        [$status, $out, $err] = run(['examples/shop/bin/seed', '--customers=2', "--database={$database}"]);
        $connection = PdoConnection::sqlite($database);

        expect($status)->toBe(0, $err)
            ->and($out)->toContain('Dropped every table and ran 28 migration(s).')
            ->and($out)->toContain('24 products, 3 customers')
            ->and(Migrations::migrator($connection)->pending())->toBe([])
            ->and($connection->select('SELECT COUNT(*) AS n FROM staff'))->toBe([['n' => 1]]);
    });

    it('seeds again from nothing, and refuses to seed what is seeded already', function () {
        $database = database();
        run(['examples/shop/bin/seed', '--customers=2', "--database={$database}"]);
        $connection = PdoConnection::sqlite($database);
        $connection->execute("UPDATE customers SET name = 'Changed'");

        [$again, , $againErr] = run([PHP_BINARY, 'bin/tinvar', 'db:seed', "--database={$database}"]);
        [$fresh, , $freshErr] = run(['examples/shop/bin/seed', '--customers=1', "--database={$database}"]);

        expect($again)->toBe(1)
            ->and($againErr)->toContain('seeded already')
            ->and($fresh)->toBe(0, $freshErr)
            ->and($connection->select('SELECT COUNT(*) AS n FROM customers'))->toBe([['n' => 3]])
            ->and($connection->select("SELECT COUNT(*) AS n FROM customers WHERE name = 'Changed'"))->toBe([['n' => 0]]);
    });

    // A seeded database has accounts whose passwords are in this
    // repository; one in production would be a way in.
    it('refuses to seed where the environment says production, --non-production or not', function () {
        $database = database();

        [$status, , $err] = run([PHP_BINARY, 'bin/tinvar', 'db:seed', "--database={$database}"], ['APP_ENV' => 'production', 'TINVAR_BUILD_DIR' => \Tinvar\Bench\Shop::productionBuild()]);
        [$flagged, , $flaggedErr] = run(['examples/shop/bin/seed', '--non-production', "--database={$database}"], ['NODE_ENV' => 'production']);

        expect($status)->toBe(1)
            ->and($err)->toContain('APP_ENV says this is production')
            ->and($flagged)->toBe(1)
            ->and($flaggedErr)->toContain('NODE_ENV says this is production. It is refused here, --non-production or not.')
            ->and(file_exists($database))->toBeFalse();
    });

    // The seed script does not say it for whoever runs it: the database it
    // replaces could be anyone's.
    it('has the seed script refuse where nothing says this is not production, and say what to set', function () {
        $database = database();

        [$status, , $err] = run(['examples/shop/bin/seed', '--customers=1', "--database={$database}"], ['TINVAR_ENV' => null]);
        [$fresh, , $freshErr] = run([PHP_BINARY, 'bin/tinvar', 'migrate:fresh', '--force', "--database={$database}"], ['TINVAR_ENV' => null]);

        expect($status)->toBe(1)
            ->and($err)->toContain('Set TINVAR_ENV=local (or development, or testing), or pass --non-production to say so for this run.')
            ->and($fresh)->toBe(1)
            ->and($freshErr)->toContain('migrate:fresh drops every table, and nothing says this is not production.')
            ->and(file_exists($database))->toBeFalse();
    });

    it('has the seed script run where --non-production says so', function () {
        $database = database();

        [$status, $out, $err] = run(['examples/shop/bin/seed', '--non-production', '--customers=1', "--database={$database}"], ['TINVAR_ENV' => null]);

        expect($status)->toBe(0, $err)
            ->and($out)->toContain('24 products, 2 customers');
    });
});
