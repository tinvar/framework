<?php

declare(strict_types=1);

/**
 * Postgres row-level security under a whole request: the route resolves
 * the tenant from the host, and a loader that reads around the ORM still
 * sees that tenant's rows alone.
 *
 * The rest of the row-level security suite is the database package's
 * (packages/database/tests/Orm/RowLevelSecurityTest.php) and runs with
 * nothing of the framework installed; this one needs the framework's
 * kernel, so it is the framework's (ADR 0055).
 */

namespace Tinvar\Tests\Tenancy\RowLevelSecurityRequest;

use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Databases;
use Tinvar\Tests\Tenancy\Fixtures\App;

use function Tinvar\Tests\Orm\Fixtures\withPolicies;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/policies.php';
require_once __DIR__ . '/Fixtures/app.php';

describe('on Postgres', function () {
    // The whole request path: the route resolves the tenant from the host,
    // and a loader that reads around the ORM still sees that tenant's rows
    // alone -- set by the request, inside its read-only transaction.
    it('sets the tenant a request resolves, so a loader reading around the ORM sees only its rows', function () {
        withPolicies(function (PdoConnection $connection, \PDO $pdo): void {
            $app = new App($connection);

            $acme = App::json($app->get('/t/raw', 'acme.test'))['page'];
            $after = $pdo->query("SELECT current_setting('app.tenant', true)")->fetchColumn();

            expect($acme)->toBe(['tenant' => 'acme', 'names' => ['acme-script-1', 'acme-script-2']])
                ->and($after)->toBe('');
        });
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');
