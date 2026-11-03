<?php

declare(strict_types=1);

/**
 * An admin resource of a #[Searchable] model searches it through the search
 * module's index (ADR 0154), held to the tenant as every search is: a word
 * of a field no column searches finds its row, another tenant's row with
 * the word is never listed, and the box shows with no searchable column. A
 * model that is not #[Searchable] -- or an application with no search --
 * is searched by LIKE over its searchable() columns, as before. On SQLite,
 * and on Postgres, where row-level security holds the index and the rows.
 */

namespace Tinvar\Tests\Resources\SearchIndex;

use Tinvar\Attributes\AdminResource;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Container\Container;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\RowLevelSecurity;
use Tinvar\Resources\Column;
use Tinvar\Resources\Data\ResourceIndexData;
use Tinvar\Resources\Resource;
use Tinvar\Resources\ResourceSearch;
use Tinvar\Search\DatabaseSearch;
use Tinvar\Search\ResourceSearchIndex;
use Tinvar\Search\Search;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Search\Fixtures\Note;
use Tinvar\Tests\Search\Fixtures\PlainRow;
use Tinvar\Tests\Search\Fixtures\Ticket;
use Tinvar\Tests\Support\Fixtures\Databases;

use function Tinvar\Tests\Search\Fixtures\createTables;
use function Tinvar\Tests\Search\Fixtures\note;
use function Tinvar\Tests\Search\Fixtures\sqliteSearch;
use function Tinvar\Tests\Search\Fixtures\ticket;

require_once __DIR__ . '/../Search/Fixtures/search.php';
require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** Tickets, listed by subject alone: no column is searchable(). */
#[AdminResource(model: Ticket::class, panel: 'staff')]
final class TicketsResource extends Resource
{
    public static function columns(): array
    {
        return [Column::make('subject')];
    }
}

/** Notes, searchable by title by LIKE -- and #[Searchable] too. */
#[AdminResource(model: Note::class, panel: 'staff')]
final class NotesResource extends Resource
{
    public static function columns(): array
    {
        return [Column::make('title')->searchable()];
    }
}

/** Plain rows: not #[Searchable], so searched by LIKE over their name. */
#[AdminResource(model: PlainRow::class, panel: 'staff')]
final class PlainRowsResource extends Resource
{
    public static function columns(): array
    {
        return [Column::make('name')->searchable()];
    }
}

/** @param class-string<Resource> $resource */
function listed(Orm $orm, ?Search $search, string $resource, string $term): ResourceIndexData
{
    return new $resource($orm, new AllowAllGate(), $search === null ? null : new ResourceSearchIndex($search))
        ->index(new Request('GET', '/', [], $term === '' ? [] : ['search' => $term]));
}

/** @return list<string> */
function titles(ResourceIndexData $list): array
{
    return array_map(static fn ($row): string => $row->title, $list->rows);
}

/** Two tenants' tickets, a word in a body no column searches. */
function seed(Orm $orm): void
{
    $orm->actAs('acme', static function () use ($orm): void {
        ticket($orm, 'Printer jammed', 'paper stuck in tray two');
        ticket($orm, 'Screen flickers', 'since the update');
    });
    $orm->actAs('globex', static function () use ($orm): void {
        ticket($orm, 'Globex printer', 'paper everywhere');
    });
}

it('finds a searchable model\'s rows through the index, by a field no column searches, in the tenant alone', function () {
    [$orm, $search] = sqliteSearch();
    seed($orm);

    $orm->actAs('acme', static function () use ($orm, $search): void {
        $list = listed($orm, $search, TicketsResource::class, 'paper');

        expect($list->searchable)->toBeTrue()
            ->and(titles($list))->toBe(['Printer jammed'])
            ->and($list->total)->toBe(1)
            ->and(titles(listed($orm, $search, TicketsResource::class, 'nothing-like-it')))->toBe([])
            ->and(titles(listed($orm, $search, TicketsResource::class, '')))->toHaveCount(2);
    });
});

it('searches by the index, not LIKE, where the model is #[Searchable] and a column is searchable() too', function () {
    [$orm, $search] = sqliteSearch();
    note($orm, 'Quarterly plan', 'budget and hiring');
    note($orm, 'Budgetary', 'nothing to see');

    // LIKE '%budget%' over the title would list Budgetary; the index matches the word in the body.
    expect(titles(listed($orm, $search, NotesResource::class, 'hiring')))->toBe(['Quarterly plan'])
        ->and(titles(listed($orm, null, NotesResource::class, 'hiring')))->toBe([]);
});

it('falls back to LIKE over the searchable columns for a model that is not #[Searchable], or with no search', function () {
    [$orm, $search, $connection] = sqliteSearch();
    $connection->execute('CREATE TABLE plain_rows (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
    $connection->execute("INSERT INTO plain_rows VALUES ('0190a1b2-0000-7000-8000-000000000001', 'Lantern'), ('0190a1b2-0000-7000-8000-000000000002', 'Lamp')");

    expect(titles(listed($orm, $search, PlainRowsResource::class, 'ant')))->toBe(['Lantern'])
        ->and($orm->actAs('acme', static fn (): bool => listed($orm, null, TicketsResource::class, 'paper')->searchable))->toBeFalse();
});

it('is handed the search by the container where the application registered one, and none where it did not', function () {
    [$orm, $search] = sqliteSearch();
    $container = new Container();
    $container->instance(Orm::class, $orm);
    ResourceSearchIndex::bind($container);

    expect($container->get(ResourceSearch::class)->searches(Ticket::class))->toBeFalse();
    $container->instance(Search::class, $search);
    expect($container->get(ResourceSearch::class)->searches(Ticket::class))->toBeTrue()
        ->and($container->get(ResourceSearch::class)->searches(PlainRow::class))->toBeFalse();
});

it('holds the search to the tenant on Postgres, under row-level security', function () {
    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }

    Databases::inScratchSchema('pgsql', static function (\PDO $pdo, string $schema): void {
        createTables(new PdoConnection($pdo, 'pgsql'), 'pgsql');
        foreach (RowLevelSecurity::forModel(Ticket::class) as $sql) {
            $pdo->exec($sql);
        }
        $role = 'tinvar_resource_search_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE ROLE {$role} NOLOGIN");
        $pdo->exec("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
        $pdo->exec("SET ROLE {$role}");

        try {
            $connection = new PdoConnection($pdo, 'pgsql');
            $orm = new Orm($connection, new IdentityMap('resource-search-pg'), Tenancy::flat());
            $search = new Search($orm, DatabaseSearch::for($connection), [Note::class, Ticket::class])->observe();
            seed($orm);

            $orm->actAs('acme', static function () use ($orm, $search): void {
                expect(titles(listed($orm, $search, TicketsResource::class, 'paper')))->toBe(['Printer jammed']);
            });
            $orm->actAs('globex', static function () use ($orm, $search): void {
                expect(titles(listed($orm, $search, TicketsResource::class, 'paper')))->toBe(['Globex printer']);
            });
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
});
