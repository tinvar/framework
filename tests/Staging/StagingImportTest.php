<?php

declare(strict_types=1);

/**
 * Production's data into staging, scrubbed and re-encrypted, refused whole
 * where the plan does not cover the database (ADR 0090).
 */

namespace Tinvar\Tests\Staging\StagingImport;

use Tinvar\Orm\Migrations\ProductionMarker;
use Tinvar\Staging\ImportRefused;
use Tinvar\Staging\Scrub;
use Tinvar\Staging\ScrubPlan;
use Tinvar\Staging\StagingImport;
use Tinvar\Support\AppKey;
use Tinvar\Support\Encrypter;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const KEY = 'production-key-of-at-least-thirty-two-bytes';

const SCHEMA = [
    'CREATE TABLE tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL)',
    'CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id), email TEXT NOT NULL UNIQUE, '
        . 'name TEXT NOT NULL, password_hash TEXT NOT NULL, notes TEXT, api_token TEXT)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
    'CREATE TABLE sessions (id TEXT PRIMARY KEY, customer_id INTEGER REFERENCES customers(id))',
    'CREATE TABLE migrations (name TEXT PRIMARY KEY)',
];

function database(): \PDO
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (SCHEMA as $statement) {
        $pdo->exec($statement);
    }

    return $pdo;
}

function production(): Encrypter
{
    return new Encrypter(AppKey::forProfile(KEY, 'production'));
}

/** Staging's key: here, production's TINVAR_APP_KEY copied over, bound to staging -- which is still not production's. */
function staging(): Encrypter
{
    return new Encrypter(AppKey::forProfile(KEY, 'staging'));
}

function seeded(): \PDO
{
    $pdo = database();
    $pdo->exec("INSERT INTO tenants VALUES ('t1', 'Acme'), ('t2', 'Globex')");
    $insert = $pdo->prepare('INSERT INTO customers VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([1, 't1', 'ada@example.com', 'Ada Lovelace', password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
        production()->encrypt('allergic to peanuts', 'customers.notes'), 'sk_live_production_secret']);
    $insert->execute([2, 't2', 'grace@example.com', 'Grace Hopper', password_hash('cobol', PASSWORD_BCRYPT, ['cost' => 4]), null, 'sk_live_other']);
    $pdo->exec("INSERT INTO products VALUES (1, 'Rope'), (2, 'Lamp')");
    $pdo->exec("INSERT INTO sessions VALUES ('production-session', 1)");
    $pdo->exec("INSERT INTO migrations VALUES ('0001_create')");

    return $pdo;
}

function plan(): ScrubPlan
{
    return ScrubPlan::create()
        ->shared('tenants', ['id' => Scrub::keep(), 'name' => Scrub::pseudonym('Tenant')])
        ->table('customers', [
            'id' => Scrub::keep(),
            'tenant_id' => Scrub::keep(),
            'email' => Scrub::email(),
            'name' => Scrub::pseudonym('Customer'),
            'password_hash' => Scrub::unusablePassword(),
            'notes' => Scrub::reencrypt(),
            'api_token' => Scrub::random(),
        ], tenant: 'tenant_id')
        ->shared('products', ['id' => Scrub::keep(), 'name' => Scrub::keep()])
        ->drop('sessions')
        ->ignore('migrations', because: 'staging\'s own record of its schema');
}

/** @return list<array<string, mixed>> */
function rows(\PDO $pdo, string $table): array
{
    $statement = $pdo->query("SELECT * FROM {$table} ORDER BY 1");

    return $statement === false ? [] : array_values(array_map(static fn (array $row): array => $row, $statement->fetchAll(\PDO::FETCH_ASSOC)));
}

it('copies every row scrubbed, re-encrypted under staging\'s key, with sessions dropped', function () {
    $staging = database();
    $staging->exec("INSERT INTO sessions VALUES ('a-staging-session', NULL)");
    $staging->exec("INSERT INTO migrations VALUES ('0001_create'), ('0002_staging_only')");

    $written = new StagingImport(plan(), production(), staging())->run(seeded(), $staging);

    expect($written)->toBe(['sessions' => 0, 'tenants' => 2, 'customers' => 2, 'products' => 2]);

    $customers = rows($staging, 'customers');
    $dump = json_encode(rows($staging, 'customers'), JSON_THROW_ON_ERROR);
    foreach (['ada@example.com', 'Ada Lovelace', 'Grace', 'sk_live', 'peanuts', '$2y$'] as $production) {
        expect($dump)->not->toContain($production);
    }

    expect($customers[0]['email'])->toMatch('/^user-[0-9a-f]{16}@staging\.invalid\z/')
        ->and($customers[0]['email'])->not->toBe($customers[1]['email'])
        ->and($customers[0]['name'])->toStartWith('Customer ')
        ->and(password_verify('hunter2', (string) $customers[0]['password_hash']))->toBeFalse()
        ->and($customers[0]['api_token'])->toMatch('/^[0-9a-f]{32}\z/')
        ->and($customers[1]['notes'])->toBeNull()
        ->and(staging()->decrypt((string) $customers[0]['notes'], 'customers.notes'))->toBe('allergic to peanuts')
        ->and(production()->decrypt((string) $customers[0]['notes'], 'customers.notes'))->toBeNull()
        ->and(rows($staging, 'sessions'))->toBe([])
        ->and(array_column(rows($staging, 'migrations'), 'name'))->toBe(['0001_create', '0002_staging_only'])
        ->and(array_column(rows($staging, 'products'), 'name'))->toBe(['Rope', 'Lamp']);
});

it('copies one tenant\'s rows, and shared tables whole', function () {
    $staging = database();
    $written = new StagingImport(plan(), production(), staging())->run(seeded(), $staging, tenant: 't1');

    expect($written['customers'])->toBe(1)
        ->and(array_column(rows($staging, 'customers'), 'tenant_id'))->toBe(['t1'])
        ->and(\count(rows($staging, 'tenants')))->toBe(2);
});

it('refuses a column the plan has no rule for, and writes nothing', function () {
    $production = seeded();
    $production->exec('ALTER TABLE customers ADD COLUMN phone TEXT');
    $production->exec("UPDATE customers SET phone = '+44 20 7946 0000'");
    $staging = database();
    $staging->exec('ALTER TABLE customers ADD COLUMN phone TEXT');
    $staging->exec("INSERT INTO products VALUES (9, 'staging only')");

    expect(fn () => new StagingImport(plan(), production(), staging())->run($production, $staging))
        ->toThrow(ImportRefused::class, 'customers.phone has no rule')
        ->and(array_column(rows($staging, 'products'), 'name'))->toBe(['staging only'])
        ->and(rows($staging, 'customers'))->toBe([]);
});

it('refuses a table the plan does not name', function () {
    $production = seeded();
    $production->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, secret TEXT)');

    new StagingImport(plan(), production(), staging())->run($production, database());
})->throws(ImportRefused::class, 'the table api_keys has no rule');

it('refuses a rule for a column production does not have, and a table staging lacks', function () {
    $plan = ScrubPlan::create()
        ->shared('tenants', ['id' => Scrub::keep(), 'name' => Scrub::keep(), 'country' => Scrub::keep()])
        ->table('customers', ['id' => Scrub::keep()])
        ->shared('products', ['id' => Scrub::keep(), 'name' => Scrub::keep()])
        ->drop('sessions')
        ->ignore('migrations', because: 'staging\'s own');
    $staging = database();
    $staging->exec('DROP TABLE products');

    $refused = null;
    try {
        new StagingImport($plan, production(), staging())->run(seeded(), $staging);
    } catch (ImportRefused $e) {
        $refused = $e->getMessage();
    }

    expect($refused)->toContain('tenants.country, which production does not have')
        ->and($refused)->toContain('customers.email has no rule')
        ->and($refused)->toContain('staging has no table products');
});

it('refuses an import of one tenant through a table that says no tenant column', function () {
    $plan = ScrubPlan::create()
        ->shared('tenants', ['id' => Scrub::keep(), 'name' => Scrub::keep()])
        ->table('customers', [
            'id' => Scrub::keep(), 'tenant_id' => Scrub::keep(), 'email' => Scrub::email(), 'name' => Scrub::null(),
            'password_hash' => Scrub::unusablePassword(), 'notes' => Scrub::null(), 'api_token' => Scrub::null(),
        ])
        ->shared('products', ['id' => Scrub::keep(), 'name' => Scrub::keep()])
        ->drop('sessions')
        ->ignore('migrations', because: 'staging\'s own');

    new StagingImport($plan, production(), staging())->run(seeded(), database(), tenant: 't1');
})->throws(ImportRefused::class, "would copy every tenant's rows");

it('refuses staging\'s key where it is production\'s', function () {
    new StagingImport(plan(), production(), new Encrypter(KEY));
})->throws(ImportRefused::class, "Staging's key is production's");

it('rolls everything back when a value does not open under production\'s key', function () {
    $production = seeded();
    // Sealed under some other key: not known to be what the column says.
    $production->prepare('UPDATE customers SET notes = ? WHERE id = 2')->execute([staging()->encrypt('x', 'customers.notes')]);
    $staging = database();
    $staging->exec("INSERT INTO sessions VALUES ('kept', NULL)");

    expect(fn () => new StagingImport(plan(), production(), staging())->run($production, $staging))
        ->toThrow(ImportRefused::class, 'customers.notes does not decrypt')
        ->and(array_column(rows($staging, 'sessions'), 'id'))->toBe(['kept'])
        ->and(rows($staging, 'customers'))->toBe([]);
});

it('refuses to import a database into itself', function () {
    $pdo = seeded();

    new StagingImport(plan(), production(), staging())->run($pdo, $pdo);
})->throws(ImportRefused::class, 'one database');

it('refuses a plan that copies a credential table, or keeps a secret, without saying why', function (\Closure $plan, string $message) {
    expect($plan)->toThrow(\InvalidArgumentException::class, $message);
})->with([
    'a session table' => [static fn () => ScrubPlan::create()->table('user_sessions', ['id' => Scrub::keep()]), 'named like a credential'],
    'a secret kept' => [static fn () => ScrubPlan::create()->table('customers', ['api_token' => Scrub::keep()]), 'named like a secret'],
    'a password kept' => [static fn () => ScrubPlan::create()->table('customers', ['password_hash' => Scrub::keep()]), 'named like a secret'],
    'a secret re-encrypted' => [static fn () => ScrubPlan::create()->table('customers', ['totp_secret' => Scrub::reencrypt()]), 'named like a secret'],
    // Audit 2026-11-13: names the table net caught and the column net did not.
    'an auth code kept' => [static fn () => ScrubPlan::create()->table('customers', ['auth_code' => Scrub::keep()]), 'named like a secret'],
    'a session id kept' => [static fn () => ScrubPlan::create()->table('customers', ['session_id' => Scrub::keep()]), 'named like a secret'],
    'a signing key kept' => [static fn () => ScrubPlan::create()->table('customers', ['signing_key' => Scrub::keep()]), 'named like a secret'],
    'a nonce kept' => [static fn () => ScrubPlan::create()->table('customers', ['nonce' => Scrub::keep()]), 'named like a secret'],
    'a cookie kept' => [static fn () => ScrubPlan::create()->table('customers', ['cookie_value' => Scrub::keep()]), 'named like a secret'],
    'a remembered login kept' => [static fn () => ScrubPlan::create()->table('customers', ['remember_digest' => Scrub::keep()]), 'named like a secret'],
    'a table twice' => [static fn () => ScrubPlan::create()->drop('migrations')->ignore('migrations', because: 'x'), 'twice'],
    'a name SQL would need quoting for' => [static fn () => ScrubPlan::create()->drop('x"; DROP TABLE y; --'), 'not a table name'],
    'a tenant column scrubbed' => [static fn () => ScrubPlan::create()->table('customers', ['tenant_id' => Scrub::null()], tenant: 'tenant_id'), 'must be one it keeps'],
]);

it('keeps a secret-looking column, or copies a credential-looking table, that says why it is safe', function () {
    $plan = ScrubPlan::create()
        ->table('token_types', [
            'id' => Scrub::keep(),
            'token_count' => Scrub::keep(because: 'a count, not a token'),
            'token_note' => Scrub::reencrypt(because: 'a note about the kind, sealed for its PII'),
        ], because: 'kinds of token, none held');

    expect(array_keys($plan->tables()))->toBe(['token_types']);
});

it('keeps an author and a keyword with no reason: the column net is no wider than it must be', function () {
    $plan = ScrubPlan::create()->table('posts', ['id' => Scrub::keep(), 'author' => Scrub::keep(), 'keyword' => Scrub::keep()]);

    expect(array_keys($plan->tables()))->toBe(['posts']);
});

// Staging's own leftovers: a token table from an earlier staging life would
// go on working against the users the import brings in.
it('refuses a table staging has and the plan does not name, and writes nothing', function () {
    $staging = database();
    $staging->exec('CREATE TABLE personal_access_tokens (id INTEGER PRIMARY KEY, customer_id INTEGER, token TEXT)');
    $staging->exec("INSERT INTO personal_access_tokens VALUES (1, 1, 'still-works')");

    expect(fn () => new StagingImport(plan(), production(), staging())->run(seeded(), $staging))
        ->toThrow(ImportRefused::class, "staging's table personal_access_tokens has no rule")
        ->and(rows($staging, 'customers'))->toBe([]);
});

it('empties a dropped table staging has and production does not', function () {
    $staging = database();
    $staging->exec('CREATE TABLE remember_tokens (id INTEGER PRIMARY KEY, token TEXT)');
    $staging->exec("INSERT INTO remember_tokens VALUES (1, 'stale')");

    new StagingImport(plan()->drop('remember_tokens'), production(), staging())->run(seeded(), $staging);

    expect(rows($staging, 'remember_tokens'))->toBe([]);
});

// An ignored table is left as it is on both sides: production's sessions
// copied by other means, or staging's stale ones, would go on working.
it('refuses to ignore a credential, session, auth or token table: it is dropped', function (string $table) {
    ScrubPlan::create()->ignore($table, because: 'staging\'s own');
})->with([
    'sessions', 'api_tokens', 'personal_access_tokens', 'remember_tokens', 'oauth_clients', 'password_resets',
    'email_links', 'magic_links', 'handoff_nonces', 'auth_epochs', 'user_invitations', 'webauthn_credentials',
    'signing_keys', 'email_verifications',
])->throws(\InvalidArgumentException::class, 'is dropped');

it('copies no table named like those without saying why', function (string $table) {
    ScrubPlan::create()->table($table, ['id' => Scrub::keep()]);
})->with(['personal_access_tokens', 'oauth_clients', 'password_resets', 'magic_links', 'user_invitations', 'signing_keys'])
    ->throws(\InvalidArgumentException::class, 'named like a credential');

it('ignores a table only with a reason it holds no secret', function () {
    ScrubPlan::create()->ignore('migrations', because: '');
})->throws(\InvalidArgumentException::class, 'holds no secret');

// Production's own mark (ADR 0103): never copied, and no plan's to name --
// and a database marked with it is no staging database to write into.
it('leaves production\'s mark behind, and refuses to write into a database that has it', function () {
    $production = seeded();
    $production->exec('CREATE TABLE ' . ProductionMarker::TABLE . ' (id INTEGER PRIMARY KEY, marked_at TEXT NOT NULL)');
    $production->exec('INSERT INTO ' . ProductionMarker::TABLE . " VALUES (1, '2026-10-29T00:00:00Z')");

    $staging = database();
    $written = new StagingImport(plan(), production(), staging())->run($production, $staging);
    expect($written)->not->toHaveKey(ProductionMarker::TABLE)
        ->and(rows($staging, 'customers'))->toHaveCount(2);

    // Production's database passed as staging's.
    $marked = database();
    $marked->exec('CREATE TABLE ' . ProductionMarker::TABLE . ' (id INTEGER PRIMARY KEY, marked_at TEXT NOT NULL)');
    $marked->exec('INSERT INTO ' . ProductionMarker::TABLE . " VALUES (1, '2026-10-29T00:00:00Z')");
    $marked->exec("INSERT INTO products VALUES (9, 'Kept')");

    expect(fn () => new StagingImport(plan(), production(), staging())->run(seeded(), $marked))
        ->toThrow(ImportRefused::class, "marked production's")
        ->and(rows($marked, 'products'))->toBe([['id' => 9, 'name' => 'Kept']]);
});

/**
 * Two databases of $driver's -- production's and staging's -- each with a
 * shared parent table and a child that cascades from it, for $test.
 *
 * @param \Closure(\PDO, \PDO): void $test
 */
function cascading(string $driver, \Closure $test): void
{
    $schema = [
        'CREATE TABLE tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL)',
        'CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL)',
    ];
    $seed = static function (\PDO $pdo) use ($schema): void {
        foreach ($schema as $statement) {
            $pdo->exec($statement);
        }
        $pdo->exec("INSERT INTO tenants VALUES ('t1', 'Acme'), ('t2', 'Globex')");
        $pdo->exec("INSERT INTO customers VALUES (1, 't1', 'Ada'), (2, 't2', 'Grace')");
    };

    if ($driver === 'sqlite') {
        $production = new \PDO('sqlite::memory:');
        $staging = new \PDO('sqlite::memory:');
        foreach ([$production, $staging] as $pdo) {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $seed($pdo);
        }
        $test($production, $staging);

        return;
    }

    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    Databases::inScratchSchema('pgsql', static function (\PDO $production, string $schema) use ($seed, $test): void {
        $seed($production);
        $staging = Databases::another('pgsql');
        $other = $schema . '_staging';
        $staging->exec("CREATE SCHEMA {$other}");
        $staging->exec("SET search_path TO {$other}");
        try {
            $seed($staging);
            $test($production, $staging);
        } finally {
            if ($staging->inTransaction()) {
                $staging->rollBack();
            }
            $staging->exec("DROP SCHEMA {$other} CASCADE");
        }
    });
}

// A shared table is every tenant's: an import of one tenant writes its rows
// over staging's, and never empties it -- an ON DELETE CASCADE would take
// the other tenants' rows with it.
it('leaves other tenants\' rows alone in an import of one tenant, where a shared table cascades', function (string $driver) {
    cascading($driver, static function (\PDO $production, \PDO $staging): void {
        $production->exec("UPDATE tenants SET name = 'Acme Renamed' WHERE id = 't1'");
        $production->exec("INSERT INTO tenants VALUES ('t3', 'Initech')");
        $production->exec("UPDATE customers SET name = 'Ada Updated' WHERE id = 1");

        $plan = ScrubPlan::create()
            ->shared('tenants', ['id' => Scrub::keep(), 'name' => Scrub::keep()])
            ->table('customers', ['id' => Scrub::keep(), 'tenant_id' => Scrub::keep(), 'name' => Scrub::keep()], tenant: 'tenant_id');

        $written = new StagingImport($plan, production(), staging())->run($production, $staging, tenant: 't1');

        expect($written)->toBe(['tenants' => 3, 'customers' => 1])
            ->and(rows($staging, 'customers'))->toBe([
                ['id' => 1, 'tenant_id' => 't1', 'name' => 'Ada Updated'],
                ['id' => 2, 'tenant_id' => 't2', 'name' => 'Grace'],
            ])
            ->and(array_column(rows($staging, 'tenants'), 'name', 'id'))->toBe(['t1' => 'Acme Renamed', 't2' => 'Globex', 't3' => 'Initech']);
    });
})->with(['sqlite', 'pgsql']);

it('refuses an import of one tenant through a shared table with no primary key to write its rows over', function () {
    $production = new \PDO('sqlite::memory:');
    $staging = new \PDO('sqlite::memory:');
    foreach ([$production, $staging] as $pdo) {
        $pdo->exec('CREATE TABLE regions (name TEXT NOT NULL)');
    }
    $plan = ScrubPlan::create()->shared('regions', ['name' => Scrub::keep()]);

    new StagingImport($plan, production(), staging())->run($production, $staging, tenant: 't1');
})->throws(ImportRefused::class, 'regions is shared and has no primary key');

/**
 * Production of $driver's twice over -- the connection the import reads,
 * and another, as production's own traffic -- and staging's, each with the
 * shared parent and its child, for $test.
 *
 * @param \Closure(\PDO, \PDO, \PDO): void $test given production, production's other connection, and staging
 */
function twoWriters(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $directory = sys_get_temp_dir() . '/tinvar-staging-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        try {
            $open = static function () use ($directory): \PDO {
                $pdo = new \PDO("sqlite:{$directory}/production.sqlite");
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA busy_timeout = 2000');

                return $pdo;
            };
            cascading('sqlite', static function (\PDO $seed, \PDO $staging) use ($open, $test): void {
                $production = $open();
                $production->exec('PRAGMA journal_mode = WAL');
                $production->exec('CREATE TABLE tenants (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
                $production->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id TEXT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE, name TEXT NOT NULL)');
                $production->exec("INSERT INTO tenants VALUES ('t1', 'Acme'), ('t2', 'Globex')");
                $production->exec("INSERT INTO customers VALUES (1, 't1', 'Ada'), (2, 't2', 'Grace')");
                $test($production, $open(), $staging);
            });
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }

        return;
    }

    cascading('pgsql', static function (\PDO $production, \PDO $staging) use ($test): void {
        $other = Databases::another('pgsql');
        $other->exec('SET search_path TO ' . (string) $production->query('SELECT current_schema()')->fetchColumn());
        $test($production, $other, $staging);
    });
}

// What the import copies is production at one moment: a row production
// writes while it runs is in none of the tables, rather than in the ones
// read after the write and not the ones before.
it('reads production as it was when the import began', function (string $driver) {
    twoWriters($driver, static function (\PDO $production, \PDO $other, \PDO $staging): void {
        $wrote = false;
        $plan = ScrubPlan::create()
            ->shared('tenants', ['id' => Scrub::keep(), 'name' => Scrub::using(static function (mixed $name) use ($other, &$wrote): mixed {
                if (!$wrote) {
                    $wrote = true;
                    $other->exec("INSERT INTO customers VALUES (3, 't1', 'Written during the import')");
                }

                return $name;
            })])
            ->table('customers', ['id' => Scrub::keep(), 'tenant_id' => Scrub::keep(), 'name' => Scrub::keep()], tenant: 'tenant_id');

        new StagingImport($plan, production(), staging())->run($production, $staging);

        expect($wrote)->toBeTrue()
            ->and(array_column(rows($staging, 'customers'), 'name'))->toBe(['Ada', 'Grace'])
            ->and($production->inTransaction())->toBeFalse()
            ->and((int) $production->query('SELECT COUNT(*) FROM customers')->fetchColumn())->toBe(3);
    });
})->with(['sqlite', 'pgsql']);
