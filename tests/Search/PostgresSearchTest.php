<?php

declare(strict_types=1);

/**
 * Full-text search in Postgres (ADR 0152): a tsvector behind a GIN index,
 * and the index table held by row-level security as a #[TenantScoped]
 * table is, so even a raw read of it that forgot the predicate sees one
 * tenant's documents. Run as a role that is not a superuser, which every
 * policy binds; skipped, saying why, without a server.
 */

namespace Tinvar\Tests\Search\Postgres;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Search\DatabaseSearch;
use Tinvar\Search\PostgresSearch;
use Tinvar\Search\Search;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Search\Fixtures\Note;
use Tinvar\Tests\Search\Fixtures\Ticket;
use Tinvar\Tests\Support\Fixtures\Databases;

use function Tinvar\Tests\Search\Fixtures\createTables;
use function Tinvar\Tests\Search\Fixtures\names;
use function Tinvar\Tests\Search\Fixtures\note;
use function Tinvar\Tests\Search\Fixtures\ticket;

require_once __DIR__ . '/Fixtures/search.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/**
 * Runs $test on Postgres with the fixture tables, the index and their
 * policies, as a role the policies hold to.
 *
 * @param \Closure(Orm, Search, PdoConnection): void $test
 */
function onPostgres(\Closure $test, ?array $parents = null): void
{
    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }

    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema) use ($test, $parents): void {
        $setup = new PdoConnection($pdo, 'pgsql');
        createTables($setup, 'pgsql');
        foreach (RowLevelSecurity::forModel(Ticket::class) as $sql) {
            $pdo->exec($sql);
        }

        $role = 'tinvar_search_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");

        try {
            $connection = new PdoConnection($pdo, 'pgsql');
            $orm = new Orm($connection, new IdentityMap('search-pg'), $parents === null ? Tenancy::flat() : Tenancy::tree($parents));
            $search = new Search($orm, DatabaseSearch::for($connection), [Note::class, Ticket::class]);
            $test($orm, $search->observe(), $connection);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('RESET ROLE');
            $pdo->exec("SELECT set_config('app.tenant', '', false), set_config('app.across_tenants', 'off', false), set_config('app.subtree', '', false)");
            $pdo->exec("DROP OWNED BY {$role}");
            $pdo->exec("DROP ROLE {$role}");
        }
    });
}

/** @return list<string> the tenants of the documents a raw read with no predicate sees */
function rawTenants(PdoConnection $connection): array
{
    return array_column($connection->select("SELECT tenant_id FROM search_documents ORDER BY tenant_id COLLATE \"C\""), 'tenant_id');
}

it('is the Postgres backend for a pgsql connection', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        expect($search->backend())->toBeInstanceOf(PostgresSearch::class);
    });
});

it('finds by every word, stemmed, the last as a prefix, the heavier field first', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        note($orm, 'Kettle', 'Pairs with the blue teapots.');
        note($orm, 'Blue teapot', 'Holds a litre. A kettle is not included.');
        note($orm, 'Green plate');

        expect(names($search->for(Note::class)->query('blue teapot')->get()))->toBe(['Blue teapot', 'Kettle'])
            ->and(names($search->for(Note::class)->query('kett')->get()))->toBe(['Kettle', 'Blue teapot'])
            ->and($search->for(Note::class)->query('purple')->paginate()->total)->toBe(0);
    });
});

it('reads hostile input as words, never as tsquery syntax', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        note($orm, 'Blue mug');

        foreach (["'", 'blue & !mug', 'mug:*A', "'); DROP TABLE notes; --", '<->', '(((', '\\'] as $typed) {
            expect(fn () => $search->for(Note::class)->query($typed)->get())->not->toThrow(\Throwable::class);
        }
        expect(names($search->for(Note::class)->query('blue & !mug')->get()))->toBe(['Blue mug']);
    });
});

it('finds a tenant\'s own documents, never another\'s, and fails closed with none', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));

        expect($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed'])
            ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer on fire'])
            ->and(fn () => $search->for(Ticket::class)->query('printer')->get())->toThrow(NoTenantContext::class)
            ->and($orm->acrossTenants(fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toHaveCount(2);
    });
});

it('holds a raw read of the index to the tenant in force and to untenanted documents, by row-level security', function () {
    onPostgres(function (Orm $orm, Search $search, PdoConnection $connection): void {
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
        note($orm, 'Printer manual');

        expect($orm->actAs('acme', fn (): array => rawTenants($connection)))->toBe(['', 'acme'])
            ->and($orm->actAs('globex', fn (): array => rawTenants($connection)))->toBe(['', 'globex'])
            ->and(rawTenants($connection))->toBe([''])
            ->and($orm->acrossTenants(fn (): array => rawTenants($connection)))->toBe(['', 'acme', 'globex'])
            // An untenanted model's documents are every reader's, as its rows are.
            ->and($orm->actAs('globex', fn (): array => names($search->for(Note::class)->query('printer')->get())))->toBe(['Printer manual']);
    });
});

it('refuses a raw write of a document into another tenant, by the policy\'s check', function () {
    onPostgres(function (Orm $orm, Search $search, PdoConnection $connection): void {
        expect(fn () => $orm->actAs('acme', fn (): int => $connection->execute(
            "INSERT INTO search_documents (model, model_id, tenant_id, document) VALUES ('tickets', 'x', 'globex', to_tsvector('english', 'planted'))",
        )))->toThrow(\PDOException::class, 'row-level security');
    });
});

it('reads a subtree inside withinSubtree(), the policy widened with the ORM', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
        $orm->actAs('initech', fn () => ticket($orm, 'Printer stolen'));

        $within = $orm->actAs('acme', fn (): array => $orm->grantSubtree(fn (): array => $orm->withinSubtree(
            fn (): array => names($search->for(Ticket::class)->query('printer')->get()),
        )));
        sort($within);

        expect($within)->toBe(['Printer jammed', 'Printer on fire']);
    }, ['globex' => 'acme']);
});

it('rebuilds each tenant\'s index under the policy, each read and written as its tenant', function () {
    onPostgres(function (Orm $orm, Search $search, PdoConnection $connection): void {
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
        $orm->acrossTenants(fn (): int => $connection->execute('DELETE FROM search_documents'));

        expect($search->reindex(Ticket::class))->toBe(2)
            ->and($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed'])
            ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer on fire']);
    });
});

it('takes a deleted row out of its tenant\'s index', function () {
    onPostgres(function (Orm $orm, Search $search): void {
        $ticket = $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('acme', fn () => $orm->delete($ticket));

        expect($orm->actAs('acme', fn (): int => $search->for(Ticket::class)->query('printer')->paginate()->total))->toBe(0);
    });
});
