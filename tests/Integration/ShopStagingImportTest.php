<?php

declare(strict_types=1);

/**
 * The shop's production database into staging through its scrub plan and
 * `tinvar staging:import` (ADR 0090): nobody's address, name or password
 * arrives, no session, auth epoch, staff grant or link does, and a column
 * the plan does not know stops it whole.
 */

namespace Tinvar\Tests\Integration\ShopStagingImport;

use Shop\Database\StagingScrub;
use Tinvar\Bench\Shop;
use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Staging\ImportRefused;
use Tinvar\Staging\Scrub;
use Tinvar\Staging\StagingImportCommand;
use Tinvar\Support\AppKey;
use Tinvar\Support\Encrypter;

const PRODUCTION_KEY = 'the-shops-production-key-of-32-bytes-and-more';

const STAGING_KEY = 'the-shops-staging-key-of-32-bytes-and-more-too';

/**
 * Runs the command, and says how it ended and what it said.
 *
 * @param list<string> $arguments
 * @param array<string, string> $env
 * @return array{int, string}
 */
function import(array $arguments, array $env = [StagingImportCommand::STAGING_KEY_ENV => STAGING_KEY], ?\Tinvar\Staging\ScrubPlan $plan = null): array
{
    $said = '';
    $status = new StagingImportCommand(
        $plan ?? StagingScrub::plan(),
        static fn (): string => PRODUCTION_KEY,
        static function (string $path): \PDO {
            if (!is_file($path)) {
                throw new ImportRefused("No database at {$path}.");
            }

            return new \PDO('sqlite:' . $path);
        },
        $env,
        static function (string $line) use (&$said): void {
            $said .= $line . "\n";
        },
        static function (string $line) use (&$said): void {
            $said .= $line . "\n";
        },
    )->run($arguments);

    return [$status, $said];
}

/** @return list<array<string, mixed>> */
function rows(string $database, string $sql): array
{
    $statement = new \PDO('sqlite:' . $database)->query($sql);

    return $statement === false ? [] : array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
}

it('covers every table and column the shop\'s migrations make', function () {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();

    [$status, $said] = import(["--from={$production}", "--to={$staging}"]);

    expect($status)->toBe(0, $said);
});

it('brings nobody\'s address, name or password, and no session, epoch, grant or link', function () {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();
    // Staging's own leftovers, which name ids the import gives to others.
    new \PDO('sqlite:' . $staging)->exec("INSERT INTO sessions (id, customer_id, created_at, last_seen_at, expires_at, realm) "
        . "SELECT 'staging-session', id, 0, 0, 9999999999, 'shop' FROM customers LIMIT 1");

    [$status, $said] = import(["--from={$production}", "--to={$staging}"]);
    expect($status)->toBe(0, $said);

    $before = rows($production, 'SELECT id, email, name, password_hash FROM customers ORDER BY id');
    $after = rows($staging, 'SELECT id, email, name, password_hash FROM customers ORDER BY id');
    expect(array_column($after, 'id'))->toBe(array_column($before, 'id'));

    $dump = json_encode($after, JSON_THROW_ON_ERROR);
    foreach ($before as $customer) {
        expect($dump)->not->toContain((string) $customer['email'])
            ->and($dump)->not->toContain((string) $customer['name'])
            ->and($dump)->not->toContain((string) $customer['password_hash']);
    }
    foreach ($after as $customer) {
        expect($customer['email'])->toEndWith('@staging.invalid')
            ->and(password_verify('pellwick-demo', (string) $customer['password_hash']))->toBeFalse();
    }

    foreach (['sessions', 'auth_epochs', 'staff', 'email_links', 'handoff_nonces', 'flashes', 'receipts', 'uploads', 'jobs'] as $table) {
        expect(rows($staging, "SELECT * FROM {$table}"))->toBe([], $table);
    }
    expect(rows($staging, 'SELECT COUNT(*) AS n FROM orders'))->toBe(rows($production, 'SELECT COUNT(*) AS n FROM orders'));
});

it('stops whole at a column a migration added and the plan does not know', function () {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();
    foreach ([$production, $staging] as $database) {
        new \PDO('sqlite:' . $database)->exec('ALTER TABLE customers ADD COLUMN phone TEXT');
    }
    new \PDO('sqlite:' . $staging)->exec("UPDATE customers SET name = 'staging as it was'");

    [$status, $said] = import(["--from={$production}", "--to={$staging}"]);

    expect($status)->toBe(StagingImportCommand::REFUSED)
        ->and($said)->toContain('customers.phone has no rule')
        ->and(array_unique(array_column(rows($staging, 'SELECT name FROM customers'), 'name')))->toBe(['staging as it was']);
});

it('re-encrypts a column production sealed, under staging\'s key', function () {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();
    foreach ([$production, $staging] as $database) {
        new \PDO('sqlite:' . $database)->exec('ALTER TABLE customers ADD COLUMN notes TEXT');
    }
    $sealed = new Encrypter(PRODUCTION_KEY)->encrypt('leave it with the neighbour', 'customers.notes');
    new \PDO('sqlite:' . $production)->prepare('UPDATE customers SET notes = ?')->execute([$sealed]);

    $plan = StagingScrub::plan();
    $tables = $plan->tables();
    $columns = [...$tables['customers']['columns'], 'notes' => Scrub::reencrypt()];
    $replanned = \Tinvar\Staging\ScrubPlan::create()->table('customers', $columns);
    foreach ($tables as $table => $declared) {
        if ($table !== 'customers') {
            $declared['shared'] ? $replanned->shared($table, $declared['columns']) : $replanned->table($table, $declared['columns']);
        }
    }
    $replanned->drop(...$plan->dropped());
    $replanned->ignore('tinvar_migrations', because: 'staging\'s own')->ignore('tinvar_build_history', because: 'staging\'s own')
        ->ignore('schedule_runs', because: 'staging\'s own');

    [$status, $said] = import(["--from={$production}", "--to={$staging}"], plan: $replanned);
    expect($status)->toBe(0, $said);

    $notes = array_unique(array_column(rows($staging, 'SELECT notes FROM customers'), 'notes'));
    $staged = new Encrypter(AppKey::forProfile(STAGING_KEY, 'staging'));
    foreach ($notes as $note) {
        expect($note)->not->toBe($sealed)
            ->and($staged->decrypt((string) $note, 'customers.notes'))->toBe('leave it with the neighbour')
            ->and(new Encrypter(PRODUCTION_KEY)->decrypt((string) $note, 'customers.notes'))->toBeNull();
    }
});

it('refuses staging holding production\'s key, a run outside production, and no staging key', function (array $env, array $more, string $message) {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();

    [$status, $said] = import(["--from={$production}", "--to={$staging}", ...$more], $env);

    expect($status)->toBe(StagingImportCommand::REFUSED)->and($said)->toContain($message);
})->with([
    'production\'s key' => [[StagingImportCommand::STAGING_KEY_ENV => PRODUCTION_KEY], [], "TINVAR_APP_KEY"],
    'run under staging' => [[StagingImportCommand::STAGING_KEY_ENV => STAGING_KEY, 'TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging'], [], "production's side"],
    'no key' => [[], [], "Name staging's key"],
    'a key file that is not there' => [[], ['--staging-key-file=/nonexistent/staging.key'], 'No key file'],
]);

it('refuses a database into itself', function () {
    $database = Shop::testDatabase();

    [$status, $said] = import(["--from={$database}", "--to={$database}"]);

    expect($status)->toBe(StagingImportCommand::REFUSED)->and($said)->toContain('one database');
});

// It replaces what staging holds: asked first where a person is there,
// and copied under a progress bar, a table a step.
it('asks before replacing staging\'s data where a person is there, and imports nothing at no', function () {
    $production = Shop::testDatabase();
    $staging = Shop::testDatabase();
    new \PDO('sqlite:' . $staging)->exec("UPDATE customers SET name = 'staging as it was'");
    try {
        $prompts = Prompts::fake(['replace-staging' => false]);
        expect(fn () => import(["--from={$production}", "--to={$staging}"]))->toThrow(Cancelled::class, 'Nothing imported.')
            ->and(array_values(array_unique(array_column(rows($staging, 'SELECT name FROM customers'), 'name'))))->toBe(['staging as it was']);
        $prompts->assertAsked("Replace what staging holds in {$staging} with production's, scrubbed?");

        $prompts = Prompts::fake(['replace-staging' => true]);
        [$status, $said] = import(["--from={$production}", "--to={$staging}"]);
        expect($status)->toBe(0, $said);
        $prompts->assertOutputContains('Importing into staging: ');

        $prompts = Prompts::fake();
        [$status] = import(["--from={$production}", "--to={$staging}", '--force']);
        expect($status)->toBe(0);
        $prompts->assertNothingAsked();
    } finally {
        Prompts::restore();
    }
});
