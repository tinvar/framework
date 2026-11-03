<?php

declare(strict_types=1);

/**
 * The staging import writes only into staging's database: handed
 * production's own database as staging's -- the same server's same
 * database and schema, over a second connection, as an operator who pastes
 * production's DSN for --to does -- it refuses before a row is read, even
 * where the database carries no production marker (ADR 0090, ADR 0103).
 */

namespace Tinvar\Tests\Staging\SameDatabase;

use Tinvar\Staging\ImportRefused;
use Tinvar\Staging\Scrub;
use Tinvar\Staging\ScrubPlan;
use Tinvar\Staging\StagingImport;
use Tinvar\Support\AppKey;
use Tinvar\Support\Encrypter;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const KEY = 'production-key-of-at-least-thirty-two-bytes';

it('refuses production\'s own database given as staging\'s over a second connection, and writes nothing', function () {
    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    Databases::inScratchSchema('pgsql', static function (\PDO $production, string $schema): void {
        $production->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $production->exec("INSERT INTO customers VALUES (1, 'ada@example.com'), (2, 'grace@example.com')");

        // --to: production's DSN again, so the same database and schema.
        $to = Databases::another('pgsql');
        $to->exec("SET search_path TO {$schema}");

        $plan = ScrubPlan::create()->shared('customers', ['id' => Scrub::keep(), 'email' => Scrub::email()]);
        $import = new StagingImport($plan, new Encrypter(AppKey::forProfile(KEY, 'production')), new Encrypter(AppKey::forProfile(KEY, 'staging')));

        try {
            expect(static fn () => $import->run($production, $to))->toThrow(ImportRefused::class);
        } finally {
            if ($to->inTransaction()) {
                $to->rollBack();
            }
        }

        expect(array_column($production->query('SELECT email FROM customers ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), 'email'))
            ->toBe(['ada@example.com', 'grace@example.com']);
    });
});
