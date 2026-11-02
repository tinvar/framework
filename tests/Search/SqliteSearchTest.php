<?php

declare(strict_types=1);

/**
 * Full-text search in SQLite's FTS5 (ADR 0154): the index follows the
 * ORM's writes once they commit, a search reads its results again through
 * the ORM, and each tenant's documents are its own.
 */

namespace Tinvar\Tests\Search\Sqlite;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Searchable;
use Tinvar\Auth\Gate;
use Tinvar\Orm\NoTenantContext;
use Tinvar\Orm\Query;
use Tinvar\Search\Search;
use Tinvar\Search\SearchableModel;
use Tinvar\Search\SearchFailed;
use Tinvar\Search\SearchTerms;
use Tinvar\Search\DatabaseSearch;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Search\Fixtures\BrokenRow;
use Tinvar\Tests\Search\Fixtures\Note;
use Tinvar\Tests\Search\Fixtures\PlainRow;
use Tinvar\Tests\Search\Fixtures\Ticket;

use function Tinvar\Tests\Search\Fixtures\names;
use function Tinvar\Tests\Search\Fixtures\note;
use function Tinvar\Tests\Search\Fixtures\sqliteSearch;
use function Tinvar\Tests\Search\Fixtures\ticket;

require_once __DIR__ . '/Fixtures/search.php';

describe('the index', function () {
    it('finds a row saved, by every word, the last as a prefix, stemmed', function () {
        [$orm, $search] = sqliteSearch();
        note($orm, 'Blue mugs', 'Stoneware, holds 350 ml.');
        note($orm, 'Red teapot', 'Holds a litre of blue tea.');
        note($orm, 'Green plate');

        expect(names($search->for(Note::class)->query('blue mug')->get()))->toBe(['Blue mugs'])
            ->and(names($search->for(Note::class)->query('blue mu')->get()))->toBe(['Blue mugs'])
            ->and(names($search->for(Note::class)->query('holding')->get()))->toHaveCount(2)
            ->and($search->for(Note::class)->query('purple')->get())->toBe([]);
    });

    it('ranks a match in a heavier field first', function () {
        [$orm, $search] = sqliteSearch();
        note($orm, 'Kettle', 'Pairs with the teapot.');
        note($orm, 'Teapot', 'A kettle is not included.');

        expect(names($search->for(Note::class)->query('teapot')->get()))->toBe(['Teapot', 'Kettle'])
            ->and(names($search->for(Note::class)->query('kettle')->get()))->toBe(['Kettle', 'Teapot']);
    });

    it('follows an update, a delete, and the query builder\'s statements', function () {
        [$orm, $search] = sqliteSearch();
        $note = note($orm, 'Copper pan');
        note($orm, 'Copper pot');

        $note->title = 'Steel pan';
        $orm->save($note);
        expect(names($search->for(Note::class)->query('copper')->get()))->toBe(['Copper pot'])
            ->and(names($search->for(Note::class)->query('steel')->get()))->toBe(['Steel pan']);

        $orm->query(Note::class)->where('title', 'Copper pot')->update(['title' => 'Iron pot']);
        expect(names($search->for(Note::class)->query('iron')->get()))->toBe(['Iron pot']);

        $orm->delete($note);
        $orm->query(Note::class)->where('title', 'Iron pot')->delete();
        expect($search->for(Note::class)->query('pan')->get())->toBe([])
            ->and($search->for(Note::class)->query('pot')->get())->toBe([]);
    });

    it('indexes nothing for a write rolled back, and waits for the commit for one kept', function () {
        [$orm, $search, $connection] = sqliteSearch();
        try {
            $orm->transaction(function () use ($orm): void {
                note($orm, 'Lost lamp');

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $orm->transaction(function () use ($orm, $connection): void {
            note($orm, 'Kept lamp');
            expect($connection->select('SELECT count(*) AS n FROM search_documents')[0]['n'])->toBe(0);
        });

        expect(names($search->for(Note::class)->query('lamp')->get()))->toBe(['Kept lamp']);
    });

    it('pages results, with the index\'s count', function () {
        [$orm, $search] = sqliteSearch();
        foreach (range(1, 5) as $i) {
            note($orm, "Candle {$i}");
        }

        $page = $search->for(Note::class)->query('candle')->paginate(2, 2);
        expect($page->items)->toHaveCount(2)
            ->and($page->total)->toBe(5)
            ->and($page->lastPage())->toBe(3)
            ->and($search->for(Note::class)->query('candle')->paginate(4, 2)->items)->toBe([])
            ->and($search->for(Note::class)->query('')->paginate()->total)->toBe(0);
    });

    it('says what to do where the index has no table', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));
        $connection->execute('CREATE TABLE notes (id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL, archived BOOLEAN NOT NULL DEFAULT FALSE)');
        $search = new Search(new Orm($connection, new IdentityMap('bare')), DatabaseSearch::for($connection), [Note::class]);

        expect(fn () => $search->for(Note::class)->query('anything')->get())->toThrow(SearchFailed::class, 'SearchTables::create($schema)');
    });
});

describe('what is typed', function () {
    it('is read as words alone, whatever query syntax it holds', function () {
        expect(SearchTerms::parse('  "Blue"  mug* OR NEAR(tea pot) -kettle tenant_id:acme ')->words)
            ->toBe(['blue', 'mug', 'or', 'near', 'tea', 'pot', 'kettle', 'tenant', 'id', 'acme'])
            ->and(SearchTerms::parse('Crème brûlée 2kg')->words)->toBe(['crème', 'brûlée', '2kg'])
            ->and(SearchTerms::parse("\xff\xfe")->words)->toBe([])
            ->and(SearchTerms::parse(str_repeat('word ', 50))->words)->toBe(['word'])
            ->and(\count(SearchTerms::parse(implode(' ', range(1, 50)))->words))->toBe(SearchTerms::MAX_TERMS)
            ->and(SearchTerms::parse('blue mug')->fts5())->toBe('"blue" "mug"*')
            ->and(SearchTerms::parse('blue mug')->tsquery())->toBe('blue & mug:*');
    });

    it('never reaches FTS5 as syntax: quotes, operators and column filters find nothing they should not', function () {
        [$orm, $search] = sqliteSearch();
        note($orm, 'Blue mug');
        note($orm, 'Red mug');

        foreach (['"', '*', 'mug") OR ("', 'NEAR(', 'title:red', 'a:red OR b:blue', '^blue', "'; DROP TABLE notes; --", '-blue'] as $typed) {
            expect(fn () => $search->for(Note::class)->query($typed)->get())->not->toThrow(\Throwable::class);
        }
        expect(names($search->for(Note::class)->query('blue OR red')->get()))->toBe([])
            ->and(names($search->for(Note::class)->query('"red" mug')->get()))->toBe(['Red mug']);
    });
});

describe('reading results again through the ORM', function () {
    it('applies a filter to the models read back, and drops a key whose row is gone', function () {
        [$orm, $search, $connection] = sqliteSearch();
        note($orm, 'Linen towel');
        $archived = note($orm, 'Linen napkin');
        $archived->archived = true;
        $orm->save($archived);
        // A row gone behind the ORM's back: the index still holds its key.
        $gone = note($orm, 'Linen apron');
        $connection->execute('DELETE FROM notes WHERE id = ?', [$gone->id]);
        $orm->identityMap()->flush();

        $results = $search->for(Note::class)->query('linen')->filter(static fn (Query $q): Query => $q->where('archived', false))->get();

        expect(names($results))->toBe(['Linen towel']);
    });

    it('keeps a result only where the policy grants the ability', function () {
        $gate = new class implements Gate {
            /** @var list<string> */
            public array $asked = [];

            public function allows(Authorize $check, array $arguments): bool
            {
                $note = $arguments[$check->argument ?? ''];
                \assert($note instanceof Note);
                $this->asked[] = "{$check->ability} {$note->title}";

                return $note->title !== 'Secret recipe';
            }
        };
        [$orm, $search] = sqliteSearch(null, null, null, $gate);
        note($orm, 'Secret recipe');
        note($orm, 'Shared recipe');

        expect(names($search->for(Note::class)->query('recipe')->authorize('view')->get()))->toBe(['Shared recipe'])
            ->and($gate->asked)->toContain('view Secret recipe');
    });

    // The total is an authorization side channel if it counts what the
    // reader was refused: "3 results" for a confidential word, showing none.
    it('never counts in its total a row the policy or the filter hid', function () {
        $gate = new class implements Gate {
            public function allows(Authorize $check, array $arguments): bool
            {
                $note = $arguments[$check->argument ?? ''];
                \assert($note instanceof Note);

                return !str_starts_with($note->title, 'Confidential');
            }
        };
        [$orm, $search] = sqliteSearch(null, null, null, $gate);
        foreach (range(1, 3) as $i) {
            note($orm, "Confidential merger {$i}");
        }
        note($orm, 'Public merger notes');
        $archived = note($orm, 'Archived merger');
        $archived->archived = true;
        $orm->save($archived);

        $hidden = $search->for(Note::class)->query('confidential')->authorize('view')->paginate();
        $some = $search->for(Note::class)->query('merger')->authorize('view')->paginate(1, 1);
        $filtered = $search->for(Note::class)->query('merger')->authorize('view')
            ->filter(static fn (Query $q): Query => $q->where('archived', false))->paginate();

        expect($hidden->items)->toBe([])
            ->and($hidden->total)->toBe(0)
            ->and($hidden->lastPage())->toBeLessThanOrEqual(1)
            ->and(names($some->items))->toHaveCount(1)
            ->and($some->total)->toBe(2)
            ->and($some->lastPage())->toBe(2)
            ->and(names($search->for(Note::class)->query('merger')->authorize('view')->paginate(2, 1)->items))->toHaveCount(1)
            ->and(names($filtered->items))->toBe(['Public merger notes'])
            ->and($filtered->total)->toBe(1)
            // Unfiltered, the index's count stands: every match is the reader's to see.
            ->and($search->for(Note::class)->query('merger')->paginate()->total)->toBe(5);
    });

    it('refuses to authorize without a Gate, and a model that is not searchable', function () {
        [$orm, $search] = sqliteSearch();
        note($orm, 'Anything');

        expect(fn () => $search->for(Note::class)->query('anything')->authorize('view')->get())->toThrow(\LogicException::class, 'no Gate')
            ->and(fn () => $search->for(PlainRow::class))->toThrow(\InvalidArgumentException::class, 'not #[Searchable]');
    });
});

describe('tenancy', function () {
    it('finds a tenant\'s own documents and never another\'s', function () {
        [$orm, $search] = sqliteSearch();
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed', 'acme printer'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire', 'globex printer'));

        expect($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed'])
            ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer on fire'])
            ->and($orm->actAs('globex', fn (): int => $search->for(Ticket::class)->query('jammed')->paginate()->total))->toBe(0);
    });

    it('fails closed with no tenant in force, and reads every tenant only inside acrossTenants()', function () {
        [$orm, $search] = sqliteSearch();
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));

        expect(fn () => $search->for(Ticket::class)->query('printer')->get())->toThrow(NoTenantContext::class)
            ->and($orm->acrossTenants(fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toHaveCount(2);
    });

    it('reads a subtree inside withinSubtree(), and the tenant alone after', function () {
        [$orm, $search] = sqliteSearch(null, ['globex' => 'acme']);
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        $orm->actAs('globex', fn () => ticket($orm, 'Printer on fire'));
        $orm->actAs('initech', fn () => ticket($orm, 'Printer stolen'));

        $within = $orm->actAs('acme', fn (): array => $orm->grantSubtree(fn (): array => $orm->withinSubtree(
            fn (): array => names($search->for(Ticket::class)->query('printer')->get()),
        )));
        sort($within);

        expect($within)->toBe(['Printer jammed', 'Printer on fire'])
            ->and($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed']);
    });

    it('indexes a row written across tenants under its own tenant', function () {
        [$orm, $search] = sqliteSearch();
        $ticket = $orm->actAs('acme', fn (): Ticket => ticket($orm, 'Printer jammed'));
        $orm->acrossTenants(function () use ($orm, $ticket): void {
            $ticket->subject = 'Scanner jammed';
            $orm->save($ticket);
        });

        expect($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('scanner')->get())))->toBe(['Scanner jammed'])
            ->and($orm->actAs('globex', fn (): array => $search->for(Ticket::class)->query('scanner')->get()))->toBe([]);
    });

    it('rebuilds every tenant\'s index from the rows, each read as its tenant', function () {
        [$orm, $search, $connection] = sqliteSearch();
        $orm->actAs('acme', fn () => ticket($orm, 'Printer jammed'));
        // Rows written behind the ORM's back: in no index until a reindex.
        $connection->execute("INSERT INTO tickets VALUES ('018f0000-0000-7000-8000-000000000001', 'Printer missing', '', 'globex')");
        $connection->execute("INSERT INTO notes (id, title, body) VALUES ('018f0000-0000-7000-8000-000000000002', 'Printer manual', '')");
        $connection->execute('DELETE FROM search_documents_text');

        $told = 0;
        expect($search->reindex(Ticket::class, progress: function (int $rows) use (&$told): void {
            $told += $rows;
        }))->toBe(2)
            ->and($told)->toBe(2)
            ->and($search->reindex(Note::class))->toBe(1)
            ->and($orm->actAs('globex', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer missing'])
            ->and($orm->actAs('acme', fn (): array => names($search->for(Ticket::class)->query('printer')->get())))->toBe(['Printer jammed'])
            ->and(names($search->for(Note::class)->query('manual')->get()))->toBe(['Printer manual'])
            ->and($search->reindex(Ticket::class, 'acme'))->toBe(1)
            ->and($search->rowCount(Ticket::class))->toBe(2);
    });
});

describe('#[Searchable]', function () {
    it('refuses fields and weights it cannot rank by, saying what to write', function () {
        expect(fn () => new Searchable([]))->toThrow(\InvalidArgumentException::class, 'between 1 and')
            ->and(fn () => new Searchable(['name', 'name']))->toThrow(\InvalidArgumentException::class, 'twice')
            ->and(fn () => new Searchable(['name'], ['body' => 2]))->toThrow(\InvalidArgumentException::class, 'not one of its fields')
            ->and(fn () => new Searchable(['name'], ['name' => 0]))->toThrow(\InvalidArgumentException::class, 'above 0')
            ->and(fn () => new Searchable(['a', 'b', 'c', 'd', 'e'], ['a' => 5, 'b' => 4, 'c' => 3, 'd' => 2]))->toThrow(\InvalidArgumentException::class, 'at most 4');
    });

    it('places fields in weight classes, highest first, and refuses a field the model does not have', function () {
        $note = SearchableModel::of(Note::class);

        expect($note->classOf)->toBe(['title' => 0, 'body' => 1])
            ->and($note->weights)->toBe([3.0, 1.0, 0.0, 0.0])
            ->and($note->table)->toBe('notes')
            ->and(SearchableModel::of(Ticket::class)->tenantColumn)->toBe('tenant_id')
            ->and(fn () => SearchableModel::of(BrokenRow::class))->toThrow(\InvalidArgumentException::class, "'missing', which is no #[Column]");
    });

    it('stops a search from being built over a model it cannot read', function () {
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));

        expect(fn () => new Search(new Orm($connection, new IdentityMap('x'), Tenancy::flat()), DatabaseSearch::for($connection), [BrokenRow::class]))
            ->toThrow(\InvalidArgumentException::class, 'missing');
    });
});
